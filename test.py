import os
import re
import json
import csv
import datetime
import pymysql
import phpserialize

from urllib.parse import urlparse, urljoin
import scrapy
from scrapy.crawler import CrawlerProcess
from scrapy import signals

import pypandoc  # For HTML-to-Markdown conversion
from scrapy_playwright.page import PageMethod
from bs4 import BeautifulSoup  # For cleaning HTML and checking language

@staticmethod
async def block_unwanted_resources(route, request):
    if request.resource_type not in {"document", "script"}:
        await route.abort()
    else:
        await route.continue_()

async def init_page(page, request):
    # Apply the blocking of unwanted resources
    spider = request.meta['spider']
    if not spider.save_screenshots:
        await page.route("**/*", block_unwanted_resources)
    # If you have a custom script to add, include it here
    script_path = os.path.join(os.path.dirname(__file__), "custom_script.js")
    await page.add_init_script(path=script_path)

class DualDomainSpider(scrapy.Spider):
    name = "dual_domain_spider"

    custom_settings = {
        "DOWNLOAD_HANDLERS": {
            "http": "scrapy_playwright.handler.ScrapyPlaywrightDownloadHandler",
            "https": "scrapy_playwright.handler.ScrapyPlaywrightDownloadHandler",
        },
        "TWISTED_REACTOR": "twisted.internet.asyncioreactor.AsyncioSelectorReactor",
        "PLAYWRIGHT_LAUNCH_OPTIONS": {
            "headless": True,
            "args": [
                "--disable-lcd-text",
                "--disable-font-subpixel-positioning",
                "--disable-gpu",
                "--disable-gpu-rasterization",
            ]
        },
        "ROBOTSTXT_OBEY": False,
        "CONCURRENT_REQUESTS": 8,
        "CONCURRENT_REQUESTS_PER_DOMAIN": 8,
        "COOKIES_ENABLED": False,
        "DOWNLOAD_DELAY": 0,
        "DOWNLOAD_TIMEOUT": 60,
        "PLAYWRIGHT_DEFAULT_NAVIGATION_TIMEOUT": 60000,
        "LOG_LEVEL": "INFO",
        "AUTOTHROTTLE_ENABLED": True,
        "AUTOTHROTTLE_START_DELAY": 2,
        "AUTOTHROTTLE_MAX_DELAY": 60,
        "AUTOTHROTTLE_TARGET_CONCURRENCY": 1.0,
        "AUTOTHROTTLE_DEBUG": False,
    }

    def __init__(self, crawl_depth, reference, test, save_screenshots="false",
                 same_page_with_url_parameters=False, lang="", remove_selectors="",
                 reference_db="", test_db="", *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.crawl_depth = int(crawl_depth)
        self.start_reference = reference if reference else None
        self.test = test
        self.save_screenshots = str(save_screenshots).lower() in ("true", "1", "yes")
        self.same_page_with_url_parameters = same_page_with_url_parameters
        self.target_lang = lang.lower() if lang else None

        self.reference_db_config = self.parse_db_url(reference_db) if reference_db else None
        self.test_db_config = self.parse_db_url(test_db) if test_db else None

        self.remove_selectors = [sel.strip() for sel in remove_selectors.split(",")] if remove_selectors else []

        # Deduplication sets for each phase.
        self.seen_normalized = {1: set(), 2: set()}

        self.domain1 = self.get_domain(reference) if reference else None
        self.domain2 = self.get_domain(test)

        self.auth1 = self.get_auth_info(reference) if reference else None
        self.auth2 = self.get_auth_info(test)

        self.create_output_dirs()

        self.log_file_path = os.path.join("output", "log.txt")
        self.log_handle = None
        self.log_writer = None

    def create_output_dirs(self):
        """Create output directories for html, text, and screenshots for both domains."""
        base = "output"
        subfolders = ["html", "text", "screenshots"]
        for sub in subfolders:
            for folder in ["reference", "test"]:
                os.makedirs(os.path.join(base, sub, folder), exist_ok=True)
        os.makedirs(base, exist_ok=True)

    @classmethod
    def from_crawler(cls, crawler, *args, **kwargs):
        spider = super().from_crawler(crawler, *args, **kwargs)
        crawler.signals.connect(spider.spider_opened, signal=signals.spider_opened)
        crawler.signals.connect(spider.spider_closed, signal=signals.spider_closed)
        return spider

    def spider_opened(self, spider):
        self.log_handle = open(self.log_file_path, "w", newline="", encoding="utf-8")
        self.log_writer = csv.writer(self.log_handle)
        self.log_writer.writerow([
            "timestamp", "url", "response_code", "ttfb (ms)",
            "dom_content_loaded (ms)", "load_event (ms)", "network_idle (ms)",
            "watchdog_errors", "console_messages"
        ])

    def spider_closed(self, spider):
        if self.log_handle:
            self.log_handle.close()

    def get_domain(self, url):
        parsed = urlparse(url)
        return parsed.hostname or "unknown_domain"

    def get_auth_info(self, url):
        parsed = urlparse(url)
        if parsed.username and parsed.password:
            return {"username": parsed.username, "password": parsed.password}
        return None

    def build_meta(self, phase, depth, auth=None):
        meta = {
            "playwright": True,
            "phase": phase,
            "depth": depth,
            "playwright_page_init_callback": init_page,
            "playwright_include_page": True,
            "spider": self,
        }
        if auth:
            meta["playwright_context_kwargs"] = {"http_credentials": auth}
        return meta

    def get_domain_folder(self, domain):
        """Return 'reference' if the domain matches the reference; otherwise, 'test'."""
        return "reference" if domain == self.domain1 else "test"

    def get_output_filepath(self, domain, url, subfolder, default_ext):
        relative = self.get_relative_path(url)
        sanitized = self.sanitize_path(relative)
        folder = self.get_domain_folder(domain)
        return os.path.join("output", subfolder, folder, sanitized + default_ext)

    def sanitize_path(self, path):
        return re.sub(r'[<>:"/\\|?*]', "_", path)

    def get_relative_path(self, url):
        parsed = urlparse(url)
        path = parsed.path.lstrip("/") or "index"
        if parsed.query:
            path += "_" + parsed.query
        return path

    def get_request_relative_url(self, url):
        parsed = urlparse(url)
        relative = parsed.path
        if parsed.query:
            relative += "?" + parsed.query
        return relative

    def normalize_url(self, url):
        parsed = urlparse(url)
        if self.same_page_with_url_parameters:
            relative = parsed.path
            if parsed.query:
                relative += "?" + parsed.query
            return relative
        return parsed.path

    def should_skip_page_due_to_language(self, response):
        """Return True if the page language does not match the target language."""
        if self.target_lang:
            soup = BeautifulSoup(response.text, "html.parser")
            html_tag = soup.find("html")
            page_lang = html_tag.get("lang", "").lower() if html_tag else ""
            if page_lang != self.target_lang:
                self.logger.info(
                    f"Skipping page with lang '{page_lang}' (target: {self.target_lang}). URL: {response.request.url}"
                )
                return True
        return False

    def is_duplicate(self, response, phase):
        """Return True if the normalized URL was already processed for the given phase."""
        normalized = self.normalize_url(response.request.url)
        if normalized in self.seen_normalized[phase]:
            self.logger.info(f"Skipping duplicate phase {phase} URL: {normalized}")
            return True
        self.seen_normalized[phase].add(normalized)
        return False

    def start_requests(self):
        if self.start_reference:
            yield scrapy.Request(
                url=self.start_reference,
                callback=self.parse_page,
                meta={
                    "playwright": True,
                    "playwright_page_init_callback": init_page,
                    **self.build_meta(phase=1, depth=0, auth=self.auth1)
                },
                errback=self.errback,
            )
        else:
            yield scrapy.Request(
                url=self.test,
                callback=self.parse_page,
                meta={
                    "playwright": True,
                    "playwright_page_init_callback": init_page,
                    **self.build_meta(phase=2, depth=0, auth=self.auth2)
                },
                errback=self.errback,
            )

    def errback(self, failure):
        request = failure.request
        try:
            response_code = failure.value.response.status
        except AttributeError:
            response_code = "N/A"
        self.logger.error(f"Request failed: {request.url}. Response code: {response_code}")
        timestamp = datetime.datetime.now().isoformat()
        if self.log_writer:
            self.log_writer.writerow([timestamp, request.url, response_code, "", "", "", "", "", ""])
            self.log_handle.flush()

    async def parse_page(self, response):
        self.logger.info(f"Processing URL: {response.request.url}")
        phase = response.meta.get("phase", 1)
        current_depth = response.meta.get("depth", 0)
        domain = self.domain1 if phase == 1 else self.domain2

        # Skip if language doesn’t match.
        if self.should_skip_page_due_to_language(response):
            return

        # Deduplicate URL.
        if self.is_duplicate(response, phase):
            return

        # Capture performance metrics.
        metrics = await self.capture_performance_metrics(response)

        # Save HTML and Markdown outputs immediately.
        self.save_html(response, domain)
        self.save_markdown(response, domain)

        # Retrieve messages stored via our injected init script.
        console_messages = []
        page = response.meta.get("playwright_page")
        if page:
            try:
                console_messages = await page.evaluate("() => window.__consoleMessages || []")
                if console_messages:
                    self.logger.info(f"console messages: {console_messages}")
            except Exception as e:
                self.logger.error(f"Error retrieving console messages: {e}")

        # Log the metrics along with the console messages.
        self.log_load_metrics(response.request.url, response.status, metrics, phase, console_messages)

        # Process screenshot if enabled; if not, close the page.
        if self.save_screenshots:
            await self.process_screenshot(response, domain)
        elif page:
            await page.close()

        # Schedule corresponding test page if in phase 1 and reference is provided.
        if phase == 1 and self.start_reference:
            relative_request = self.get_request_relative_url(response.request.url)
            abs_test = urljoin(self.test, relative_request)
            yield scrapy.Request(
                url=abs_test,
                callback=self.parse_page,
                meta=self.build_meta(phase=2, depth=current_depth, auth=self.auth2),
                errback=self.errback,
                dont_filter=True,
            )

        # Follow internal links if within crawl depth, regardless of phase.
        if current_depth < self.crawl_depth:
            for req in self.follow_internal_links(response, current_depth + 1):
                yield req

    async def capture_performance_metrics(self, response):
        metrics = {"ttfb": None, "dom_content_loaded": None, "load_event": None, "network_idle": None}
        if "playwright_page" in response.meta:
            page = response.meta["playwright_page"]
            try:
                timing_json = await page.evaluate("() => JSON.stringify(window.performance.timing)")
                timing = json.loads(timing_json)
                nav_start = timing.get("navigationStart", 0)
                metrics["ttfb"] = timing.get("responseStart", 0) - nav_start
                metrics["dom_content_loaded"] = timing.get("domContentLoadedEventEnd", 0) - nav_start
                metrics["load_event"] = timing.get("loadEventEnd", 0) - nav_start
                await page.wait_for_load_state("networkidle")
                metrics["network_idle"] = await page.evaluate("() => performance.now()")
            except Exception as e:
                self.logger.error(f"Error capturing performance metrics for {response.request.url}: {e}")
        return metrics

    def follow_internal_links(self, response, next_depth):
        for link in response.css("a::attr(href)").getall():
            if link.lower().startswith(("javascript:", "mailto:", "tel:")):
                continue
            abs_url = response.urljoin(link)
            if not self.is_html_url(abs_url):
                continue
            if urlparse(abs_url).hostname != (self.domain1 if response.meta.get("phase", 1) == 1 else self.domain2):
                continue
            yield scrapy.Request(
                url=abs_url,
                callback=self.parse_page,
                meta={
                    "playwright": True,
                    "playwright_page_init_callback": init_page,
                    **self.build_meta(
                        phase=response.meta.get("phase", 1),
                        depth=next_depth,
                        auth=self.auth1 if response.meta.get("phase", 1) == 1 else self.auth2
                    )
                },
                errback=self.errback,
            )

    def is_html_url(self, url):
        non_html_ext = (
            ".jpg", ".jpeg", ".png", ".gif", ".svg", ".css",
            ".js", ".pdf", ".mp4", ".mp3", ".zip", ".rar"
        )
        return not any(urlparse(url).path.lower().endswith(ext) for ext in non_html_ext)

    def save_html(self, response, domain):
        file_path = self.get_output_filepath(domain, response.request.url, "html", ".html")
        self.write_file(file_path, response.body, binary=True)

    def clean_html(self, html):
        soup = BeautifulSoup(html, "html.parser")
        for tag in soup.find_all(['script', 'style', 'img']):
            tag.decompose()
        # Unwrap all <div> elements (in case of nested wrappers)
        for tag in soup.find_all('div'):
            tag.unwrap()
        for tag in soup.find_all():
            tag.attrs = {}
        return str(soup)

    def save_markdown(self, response, domain):
        file_path = self.get_output_filepath(domain, response.request.url, "text", ".md")
        try:
            cleaned_html = self.clean_html(response.text)
            markdown_text = pypandoc.convert_text(cleaned_html, 'md', format='html')
            markdown_text = re.sub(r'</?div>', '', markdown_text)
        except Exception as e:
            self.logger.error(f"Pandoc conversion failed for {response.request.url}: {e}")
            markdown_text = "Conversion failed."
        self.write_file(file_path, markdown_text, binary=False)

    async def remove_unwanted_selectors(self, page):
        """Remove CSS selectors specified in self.remove_selectors from the page."""
        for sel in self.remove_selectors:
            await page.evaluate(
                f"""() => {{
                    document.querySelectorAll('{sel}').forEach(e => e.remove());
                }}"""
            )

    async def process_screenshot(self, response, domain):
        page = response.meta.get("playwright_page")
        if not page:
            self.logger.error("No playwright_page in meta for screenshot!")
            return
        await self.remove_unwanted_selectors(page)
        file_path = self.get_output_filepath(domain, response.request.url, "screenshots", ".png")
        await page.screenshot(path=file_path, full_page=True)
        self.logger.info(f"Saved screenshot: {file_path}")
        await page.close()

    def write_file(self, file_path, data, binary=False):
        os.makedirs(os.path.dirname(file_path), exist_ok=True)
        mode = "wb" if binary else "w"
        with open(file_path, mode, encoding=None if binary else "utf-8") as f:
            f.write(data)

    def log_load_metrics(self, url, response_code, metrics, phase, console_messages):
        if self.log_writer:
            timestamp = datetime.datetime.now().isoformat()
            ttfb = round(metrics.get("ttfb", 0))
            dcl = round(metrics.get("dom_content_loaded", 0))
            load_evt = round(metrics.get("load_event", 0))
            network_idle = round(metrics.get("network_idle", 0))
            db_config = self.reference_db_config if phase == 1 else self.test_db_config
            watchdog_errors = self.get_watchdog_errors(url, db_config)

            # Sanitize messages by replacing newlines and excessive whitespace
            console_messages_str = " | ".join([f"{msg['type']}: {msg['text']}" for msg in console_messages])
            watchdog_errors = " ".join(watchdog_errors.splitlines())  # Flatten multi-line logs

            self.log_writer.writerow([
                timestamp, url, response_code, ttfb, dcl, load_evt, network_idle,
                console_messages_str, watchdog_errors
            ])
            self.log_handle.flush()

    def parse_db_url(self, db_url):
        parsed = urlparse(db_url)
        return {
            "host": parsed.hostname,
            "port": parsed.port or 3306,
            "user": parsed.username,
            "password": parsed.password,
            "database": parsed.path.lstrip("/")
        }

    def get_watchdog_errors(self, url, db_config):
        if not db_config:
            return "No DB Config"

        sql_query = """
            SELECT timestamp, type, message, variables, severity
            FROM watchdog
            WHERE location LIKE %s AND severity <= 4
            ORDER BY timestamp DESC
            LIMIT 5;
        """
        relative_url = self.normalize_watchdog_url(url)
        try:
            connection = pymysql.connect(
                host=db_config["host"],
                port=int(db_config["port"]),
                user=db_config["user"],
                password=db_config["password"],
                database=db_config["database"],
                charset="utf8mb4",
                cursorclass=pymysql.cursors.DictCursor
            )
            with connection:
                with connection.cursor() as cursor:
                    cursor.execute(sql_query, ("%" + relative_url + "%",))
                    logs = cursor.fetchall()

            combined_logs = []
            for log in logs:
                message = log['message']
                variables = log['variables']
                log_type = log['type']
                if variables:
                    decoded_vars = phpserialize.loads(variables, decode_strings=True, object_hook=self.ignore_php_objects)
                    message = self.replace_placeholders(message, decoded_vars)

                combined_logs.append(f"{log['timestamp']} [{log_type}]: {message}")

            return " | ".join(combined_logs) if combined_logs else "No errors"
        except pymysql.MySQLError as e:
            self.logger.error(f"Failed to fetch watchdog logs: {e}")
            return "Error fetching logs"

    def ignore_php_objects(self, class_name, obj_dict):
        """Ignore PHP objects when deserializing."""
        return "[Ignored PHP Object]"

    def safe_deserialize(self, variables):
        """Safely deserialize PHP serialized data while ignoring objects."""
        try:
            # Ensure variables are bytes before deserializing
            if isinstance(variables, str):
                variables = variables.encode("utf-8")

            # Deserialize with object_hook to ignore PHP objects
            decoded_vars = phpserialize.loads(variables, decode_strings=True, object_hook=ignore_php_objects)

            # Convert all values to strings
            return {key: str(value) for key, value in decoded_vars.items()}
        except (UnicodeEncodeError, ValueError, Exception) as e:
            self.logger.error(f"Error deserializing variables: {e}")
            return {}

    def replace_placeholders(self, message, variables):
        return re.sub(r'(@\w+|%\w+)', lambda match: str(variables.get(match.group(0), match.group(0))), message)

    def normalize_watchdog_url(self, url):
        parsed = urlparse(url)
        return parsed.path

if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(description="Dual domain crawler with comparison functionality.")
    parser.add_argument("--reference", help="Starting URL for domain 1.")
    parser.add_argument("--test", required=True, help="Starting URL for domain 2.")
    parser.add_argument("--depth", type=int, default=2, help="Crawl depth.")
    parser.add_argument("--screenshots", action="store_true", help="Enable saving screenshots.")
    parser.add_argument("--same-page-with-url-parameters", action="store_true",
                        help="Treat pages with different query parameters as distinct.")
    parser.add_argument("--lang", default="", help="Only process pages with <html lang='X'> matching this language (e.g., 'en').")
    parser.add_argument("--remove-selectors", default="", help="Comma-separated list of CSS selectors to remove before screenshot.")
    parser.add_argument("--reference-db", default="", help="MySQL connection string for the reference site (mysql://user:pass@host:port/dbname)")
    parser.add_argument("--test-db", default="", help="MySQL connection string for the test site (mysql://user:pass@host:port/dbname)")
    args = parser.parse_args()

    process = CrawlerProcess()
    process.crawl(
        DualDomainSpider,
        crawl_depth=args.depth,
        reference=args.reference,
        test=args.test,
        save_screenshots=str(args.screenshots).lower(),
        same_page_with_url_parameters=args.same_page_with_url_parameters,
        lang=args.lang,
        remove_selectors=args.remove_selectors,
        reference_db=args.reference_db,
        test_db=args.test_db
    )
    process.start()