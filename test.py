import os
import re
import json
import csv
import datetime
import hashlib
import pymysql
import phpserialize
import asyncio

from urllib.parse import urlparse, urljoin
import scrapy
from scrapy.crawler import CrawlerProcess
from scrapy import signals

import pypandoc  # For HTML-to-Markdown conversion
from scrapy_playwright.page import PageMethod
from bs4 import BeautifulSoup  # For cleaning HTML and checking language

# Set the logging level for pypandoc to WARNING
import logging
logging.getLogger("pypandoc").setLevel(logging.WARNING)

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
        await page.route("**/*", block_unwanted_resources) # Normally this is what we want for more efficient crawling
        # await page.route("**/*", lambda route, request: route.continue_())  # Do not block any resources
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
            ],
        },
        "ROBOTSTXT_OBEY": False,
        "COOKIES_ENABLED": False,
        "DOWNLOAD_DELAY": 0,
        "DOWNLOAD_TIMEOUT": 180,
        "PLAYWRIGHT_DEFAULT_NAVIGATION_TIMEOUT": 180000,
        "PLAYWRIGHT_BROWSER_TYPE": "chromium",
        "LOG_LEVEL": "WARNING",
        "AUTOTHROTTLE_ENABLED": False,
        "AUTOTHROTTLE_START_DELAY": 2,
        "AUTOTHROTTLE_MAX_DELAY": 60,
        "AUTOTHROTTLE_TARGET_CONCURRENCY": 1.0,
        "AUTOTHROTTLE_DEBUG": False,
    }

    def __init__(self, crawl_depth, reference, test, save_screenshots="false",
                 lang="", remove_selectors="", reference_db="",
                 test_db="", limit_same_url_with_parameters=0,
                 delay_before_capture=0, exclude_paths="",
                 exclude_links_inside_classes="", *args, **kwargs):
        super().__init__(*args, **kwargs)

        # Trim trailing slashes for consistency.
        self.start_reference = reference.rstrip("/") if reference else None
        self.test = test.rstrip("/") if test else None

        self.crawl_depth = int(crawl_depth)
        self.save_screenshots = str(save_screenshots).lower() in ("true", "1", "yes")
        self.target_lang = lang.lower() if lang else None

        self.reference_db_config = self.parse_db_url(reference_db) if reference_db else None
        self.test_db_config = self.parse_db_url(test_db) if test_db else None

        # Process selectors to ensure they're proper CSS selectors
        self.remove_selectors = []
        if remove_selectors:
            for sel in remove_selectors.split(","):
                sel = sel.strip()
                if sel:
                    # If the selector doesn't start with a CSS selector character, 
                    # assume it's a class name and prepend with '.'
                    if not sel.startswith(('.', '#', '[', '*', ':', '>')) and not ' ' in sel:
                        sel = f".{sel}"
                    self.remove_selectors.append(sel)
                    
        # Compile exclude‑path regexes (comma‑separated list)
        self.exclude_patterns = [re.compile(p.strip()) for p in exclude_paths.split(",") if p.strip()]
        self.exclude_links_inside_classes = [
            c.strip() for c in exclude_links_inside_classes.split(",") if c.strip()
        ]

        # Use a dictionary mapping normalized URL (full URL with query parameters) to count for each phase.
        self.seen_normalized = {1: {}, 2: {}}

        self.limit_same_url_with_parameters = int(limit_same_url_with_parameters)
        self.delay_before_capture = float(delay_before_capture)

        self.domain1 = self.get_domain(self.start_reference) if self.start_reference else None
        self.domain2 = self.get_domain(self.test)

        self.auth1 = self.get_auth_info(self.start_reference) if self.start_reference else None
        self.auth2 = self.get_auth_info(self.test)

        self.create_output_dirs()

        self.log_file_path = os.path.join("output", "log.csv")
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
            "timestamp", "request url", "final url", "response_code", "ttfb (ms)",
            "dom_content_loaded (ms)", "load_event (ms)", "network_idle (ms)",
            "age", "cache-control", "date", "expires",
            "last-modified", "cf-cache-status", "x-cache", "x-cache-hits", "x-drupal-dynamic-cache", "vary", "set-cookie", "x-drupal-cache-contexts", "x-drupal-cache-max-age", "x-drupal-cache-tags",
            "console_messages", "watchdog_errors"
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
            "playwright_context": "new",
            "playwright_context_kwargs": {
                "user_agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36"
            }
        }
        if auth:
            if "playwright_context_kwargs" not in meta:
                meta["playwright_context_kwargs"] = {}
            meta["playwright_context_kwargs"]["http_credentials"] = auth
        return meta

    def get_domain_folder(self, domain):
        """Return 'reference' if the domain matches the reference; otherwise, 'test'."""
        return "reference" if domain == self.domain1 else "test"

    def get_output_filepath(self, domain, url, subfolder, default_ext):
        relative = self.get_relative_path(url)
        sanitized = self.sanitize_path(relative)
        folder = self.get_domain_folder(domain)
        return os.path.join("output", subfolder, folder, sanitized + default_ext)

    def sanitize_path(self, path, max_length=255):
        sanitized = re.sub(r'[<>:"/\\|?*]', "_", path)
        if len(sanitized) > max_length:
            hash_object = hashlib.md5(path.encode())
            hash_hex = hash_object.hexdigest()[:8]  # Use the first 8 characters of the hash
            sanitized = sanitized[:max_length - 9] + "_" + hash_hex  # Adjust length to accommodate hash and underscore
        return sanitized

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
        return parsed.geturl()

    def should_skip_page_due_to_language(self, response):
        """Return True if the page language is not empty and does not match the target language."""
        if self.target_lang:
            soup = BeautifulSoup(response.text, "html.parser")
            html_tag = soup.find("html")
            page_lang = html_tag.get("lang", "").lower() if html_tag else ""
            if page_lang and page_lang != self.target_lang:
                self.logger.info(
                    f"Skipping page with lang '{page_lang}' (target: {self.target_lang}). URL: {response.request.url}"
                )
                return True
        return False

    def should_exclude(self, url):
        """Return True when the URL matches any exclude-path regex."""
        return any(p.search(url) for p in self.exclude_patterns)

    def link_in_excluded_section(self, link_tag):
        """
        Return True when the <a> tag is inside any ancestor element
        whose class attribute contains one of the excluded class names.
        """
        if not self.exclude_links_inside_classes:
            return False
        for ancestor in link_tag.parents:
            if not hasattr(ancestor, "get"):  # Reached BeautifulSoup root
                return False
            classes = ancestor.get("class", [])
            if any(cls in classes for cls in self.exclude_links_inside_classes):
                return True
        return False

    def get_duplicate_key(self, url):
        parsed = urlparse(url)
        # Use only scheme, hostname, and path for duplicate detection.
        return f"{parsed.scheme}://{parsed.netloc}{parsed.path}"

    def is_duplicate(self, response, phase):
        # Always use the base URL (without query parameters) for duplicate detection.
        key = self.get_duplicate_key(response.request.url)
        count = self.seen_normalized[phase].get(key, 0)
        if count >= self.limit_same_url_with_parameters:
            self.logger.info(f"Skipping duplicate phase {phase} URL: {key} reached limit {count}")
            return True
        self.seen_normalized[phase][key] = count + 1
        return False

    def start_requests(self):
        """Begin crawl only on the TEST domain (phase 2)."""
        if self.should_exclude(self.test):
            self.logger.info(f"Skipping excluded start URL: {self.test}")
            return
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
        response_code = "N/A"

        if hasattr(failure.value, 'response'):
            response_code = getattr(failure.value.response, "status", "N/A")
        elif isinstance(failure.value, TimeoutError):
            self.logger.error(f"Request timed out: {request.url}")

        self.logger.error(f"Request failed: {request.url}. Response code: {response_code}")

        # Get Playwright page from meta
        page = request.meta.get("playwright_page")
        if page:
            asyncio.create_task(page.close())  # Close the page asynchronously

        # Log the failure
        timestamp = datetime.datetime.now().isoformat()
        if self.log_writer:
            self.log_writer.writerow([timestamp, request.url, response_code, "", "", "", "", "", ""])
            self.log_handle.flush()

    async def parse_page(self, response):
        print(f"Queue: {len(self.crawler.engine.slot.scheduler)}. Downloaded: {self.crawler.stats.get_value('response_received_count', 0)}. Processing URL: {response.request.url}")
        phase = response.meta.get("phase", 1)
        current_depth = response.meta.get("depth", 0)
        domain = self.domain1 if phase == 1 else self.domain2
        # Skip if URL matches an exclude-path pattern
        if self.should_exclude(response.request.url):
            return

        # Get the Playwright page reference
        page = response.meta.get("playwright_page")

        try:
            collected_requests = []

            async def _core_runner():
                async for req in self._parse_core(response, phase, current_depth, domain, page):
                    collected_requests.append(req)

            await asyncio.wait_for(_core_runner(), timeout=120)  # whole parse timeout
            # Yield any requests produced by _parse_core
            for req in collected_requests:
                yield req
        except asyncio.TimeoutError:
            self.logger.warning(f"Whole parse timed‑out (120 s) for {response.request.url}")
        except Exception as e:
            self.logger.error(f"Unhandled error in parse_page for {response.request.url}: {e}")
        finally:
            # Log completion for debugging
            self.logger.info(f"Finished parse of {response.request.url}")
            # Ensure the Playwright page is closed to avoid resource leaks
            if page:
                await page.close()

    async def _parse_core(self, response, phase, current_depth, domain, page):
        """
        All the existing logic from parse_page is moved here so we can wrap it
        with asyncio.wait_for in the outer method.
        """
        # --- BEGIN moved logic ---
        # Check if the response content type is text-based
        content_type = response.headers.get('Content-Type', b'').decode('utf-8')
        if not content_type.startswith('text'):
            self.logger.error(f"Skipping non-text response: {response.request.url} (Content-Type: {content_type})")
            return

        # Skip if language doesn’t match.
        if self.should_skip_page_due_to_language(response):
            return

        # Deduplicate URL.
        if self.is_duplicate(response, phase):
            return

        # Capture performance metrics.
        metrics = await self.capture_performance_metrics(response)
        # Optional delay before capturing markup and text
        if self.delay_before_capture > 0:
            await asyncio.sleep(self.delay_before_capture)

        # Remove unwanted selectors from the page before saving content
        if page:
            await self.remove_unwanted_selectors(page)
            # Get content AFTER removing unwanted selectors
            html_content = await page.content()
            self.save_html_content(html_content, response.request.url, domain)
            # Use the cleaned HTML for markdown conversion as well
            self.save_markdown_from_content(html_content, response.request.url, domain)
        else:
            # For non-Playwright responses, we'll clean the HTML manually
            html_content = response.text
            cleaned_html = self.apply_selector_removal_to_html(html_content)
            self.save_html_content(cleaned_html, response.request.url, domain)
            self.save_markdown_from_content(cleaned_html, response.request.url, domain)

        # Retrieve messages stored via our injected init script.
        console_messages = []
        if page:
            try:
                console_messages = await page.evaluate("() => window.__consoleMessages || []")
                if console_messages:
                    self.logger.info(f"Console messages: {console_messages}")
            except Exception as e:
                self.logger.error(f"Error retrieving console messages: {e}")

        # Log the metrics along with the console messages.
        self.log_load_metrics(response.request.url, response.url, response.status, metrics, phase, console_messages, response)

        # Process screenshot if enabled.
        if self.save_screenshots:
            await self.process_screenshot(response, domain)

        # Schedule corresponding reference page when we're on the TEST site (phase 2).
        if phase == 2 and self.start_reference:
            relative_request = self.get_request_relative_url(response.request.url)
            abs_ref = urljoin(self.start_reference, relative_request)
            if not self.should_exclude(abs_ref):
                yield scrapy.Request(
                    url=abs_ref,
                    callback=self.parse_page,
                    meta=self.build_meta(phase=1, depth=current_depth, auth=self.auth1),
                    errback=self.errback,
                    dont_filter=True,
                )

        # Follow internal links **only** for the test site (phase 2).
        if phase == 2 and current_depth < self.crawl_depth:
            if page:
                html_content = await page.content()
                for req in self.follow_internal_links(html_content, response, current_depth + 1):
                    yield req
            else:
                for req in self.follow_internal_links(response.text, response, current_depth + 1):
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
                try:
                    # Wait up to 60 seconds (60 000 ms) for network to go idle
                    await page.wait_for_load_state("networkidle", timeout=60_000)
                except Exception as e:
                    self.logger.warning(
                        f"Timed out after 60 s waiting for networkidle on {response.request.url}: {e}"
                    )
                metrics["network_idle"] = await page.evaluate("() => performance.now()")
            except Exception as e:
                self.logger.error(f"Error capturing performance metrics for {response.request.url}: {e}")
        return metrics

    def follow_internal_links(self, html_content, response, next_depth):
        if not html_content.strip():
            self.logger.error(f"Empty response body for URL: {response.request.url}")
            return

        links_followed = 0
        soup = BeautifulSoup(html_content, "html.parser")
        phase = response.meta.get("phase", 1)

        for link in soup.select("a[href]"):
            if self.link_in_excluded_section(link):
                continue
            href = link.get("href")
            if href.lower().startswith(("javascript:", "mailto:", "tel:")):
                continue
            abs_url = response.urljoin(href)
            if self.should_exclude(abs_url):
                continue
            if not self.is_html_url(abs_url):
                continue
            if urlparse(abs_url).hostname != (self.domain1 if phase == 1 else self.domain2):
                continue

            # Check for duplicates BEFORE yielding new requests
            duplicate_key = self.get_duplicate_key(abs_url)
            count = self.seen_normalized[phase].get(duplicate_key, 0)

            if self.limit_same_url_with_parameters > 0 and count >= self.limit_same_url_with_parameters:
                self.logger.info(f"Skip scheduling duplicate URL: {duplicate_key} (count: {count})")
                continue

            # Update counter for this URL
            self.seen_normalized[phase][duplicate_key] = count + 1

            links_followed += 1
            yield scrapy.Request(
                url=abs_url,
                callback=self.parse_page,
                meta={
                    "playwright": True,
                    "playwright_page_init_callback": init_page,
                    **self.build_meta(
                        phase=phase,
                        depth=next_depth,
                        auth=self.auth1 if phase == 1 else self.auth2
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
        """Legacy method maintained for compatibility"""
        cleaned_html = self.apply_selector_removal_to_html(response.text)
        file_path = self.get_output_filepath(domain, response.request.url, "html", ".html")
        self.write_file(file_path, cleaned_html, binary=False)

    def save_html_content(self, html_content, url, domain):
        """
        Save raw HTML text that we already fetched from a Playwright page.
        """
        file_path = self.get_output_filepath(domain, url, "html", ".html")
        self.write_file(file_path, html_content, binary=False)

    def apply_selector_removal_to_html(self, html_content):
        """Apply selector removal using BeautifulSoup for non-Playwright content"""
        if not self.remove_selectors:
            return html_content
            
        soup = BeautifulSoup(html_content, "html.parser")
        for selector in self.remove_selectors:
            for element in soup.select(selector):
                element.decompose()
        return str(soup)

    def clean_html(self, html):
        """Clean HTML for text extraction, removing scripts, styles, and images"""
        soup = BeautifulSoup(html, "html.parser")
        
        # First apply custom selector removal
        for selector in self.remove_selectors:
            for element in soup.select(selector):
                element.decompose()
                
        # Then do standard cleanup
        for tag in soup.find_all(['script', 'style', 'img']):
            tag.decompose()
        # Unwrap all <div> elements (in case of nested wrappers)
        for tag in soup.find_all('div'):
            tag.unwrap()
        for tag in soup.find_all():
            tag.attrs = {}
        return str(soup)

    def save_markdown(self, response, domain):
        """Legacy method maintained for compatibility"""
        cleaned_html = self.clean_html(response.text)
        self.save_markdown_from_content(cleaned_html, response.request.url, domain)

    def save_markdown_from_content(self, html_content, url, domain):
        file_path = self.get_output_filepath(domain, url, "text", ".md")
        try:
            cleaned_html = self.clean_html(html_content)
            markdown_text = pypandoc.convert_text(cleaned_html, 'md', format='html')
            markdown_text = re.sub(r'</?div>', '', markdown_text)
        except Exception as e:
            self.logger.error(f"Pandoc conversion failed for {url}: {e}")
            markdown_text = "Conversion failed."
        self.write_file(file_path, markdown_text, binary=False)

    async def remove_unwanted_selectors(self, page):
        """Remove CSS selectors specified in self.remove_selectors from the page."""
        for sel in self.remove_selectors:
            try:
                # First count the elements to remove
                count = await page.evaluate(f"() => document.querySelectorAll('{sel}').length")
                if count > 0:
                    self.logger.info(f"Removing {count} elements matching selector: {sel}")
                    # Then remove them
                    await page.evaluate(
                        f"""() => {{
                            document.querySelectorAll('{sel}').forEach(e => e.remove());
                        }}"""
                    )
            except Exception as e:
                self.logger.error(f"Error removing selector '{sel}': {e}")

    async def process_screenshot(self, response, domain):
        page = response.meta.get("playwright_page")
        if self.delay_before_capture > 0:
            await asyncio.sleep(self.delay_before_capture)
        if not page:
            self.logger.error("No playwright_page in meta for screenshot!")
            return
        # Remove unwanted selectors is already called earlier in the process
        file_path = self.get_output_filepath(domain, response.request.url, "screenshots", ".png")
        await page.screenshot(path=file_path, full_page=True)
        self.logger.info(f"Saved screenshot: {file_path}")
        await page.close()

    def write_file(self, file_path, data, binary=False):
        os.makedirs(os.path.dirname(file_path), exist_ok=True)
        mode = "wb" if binary else "w"
        with open(file_path, mode, encoding=None if binary else "utf-8") as f:
            f.write(data)

    def log_load_metrics(self, url_request, url_final, response_code, metrics, phase, console_messages, response):
        if self.log_writer:
            timestamp = datetime.datetime.now().isoformat()
            ttfb = round(metrics.get("ttfb", 0))
            dcl = round(metrics.get("dom_content_loaded", 0))
            load_evt = round(metrics.get("load_event", 0))
            network_idle = round(metrics.get("network_idle", 0)) if metrics.get("network_idle") is not None else 0
            db_config = self.reference_db_config if phase == 1 else self.test_db_config

            # Create a list with the request URL, any redirect URLs, and the final URL.
            urls_to_check = [url_request] + response.meta.get('redirect_urls', []) + [url_final]
            watchdog_errors = self.get_watchdog_errors(urls_to_check, db_config)

            # Only include the final URL if it differs from the request URL.
            final_url_to_print = url_final if url_final.rstrip("/") != url_request.rstrip("/") else ""

            # Sanitize messages by replacing newlines and excessive whitespace
            console_messages_str = " | ".join([f"{msg['type']}: {msg['text']}" for msg in console_messages])
            watchdog_errors = " ".join(watchdog_errors.splitlines())  # Flatten multi-line logs

            # Extract caching headers safely
            headers = response.headers
            age = headers.get("Age", b"").decode("utf-8")
            cache_control = headers.get("Cache-Control", b"").decode("utf-8")
            date = headers.get("Date", b"").decode("utf-8")
            expires = headers.get("Expires", b"").decode("utf-8")
            last_modified = headers.get("Last-Modified", b"").decode("utf-8")
            cf_cache_status = headers.get("CF-Cache-Status", b"").decode("utf-8")
            x_cache = headers.get("X-Cache", b"").decode("utf-8")
            x_cache_hits = headers.get("X-Cache-Hits", b"").decode("utf-8")
            x_drupal_dynamic_cache = headers.get("X-Drupal-Dynamic-Cache", b"").decode("utf-8")
            vary = headers.get("Vary", b"").decode("utf-8")
            set_cookie = headers.get("Set-Cookie", b"").decode("utf-8")
            x_drupal_cache_contexts = headers.get("x-drupal-cache-contexts", b"").decode("utf-8")
            x_drupal_cache_max_age = headers.get("x-drupal-cache-max-age", b"").decode("utf-8")
            x_drupal_cache_tags = headers.get("x-drupal-cache-tags", b"").decode("utf-8")

            self.log_writer.writerow([
                timestamp, url_request, final_url_to_print, response_code, ttfb, dcl, load_evt, network_idle,
                age, cache_control, date, expires,
                last_modified, cf_cache_status, x_cache, x_cache_hits, x_drupal_dynamic_cache, vary, set_cookie, x_drupal_cache_contexts, x_drupal_cache_max_age, x_drupal_cache_tags,
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

    def get_watchdog_errors(self, urls, db_config):
        if not db_config:
            return "No DB Config"

        # Ensure urls is a list
        if not isinstance(urls, list):
            urls = [urls]

        # Build a dynamic WHERE clause for each URL.
        conditions = []
        params = []
        for u in urls:
            relative_url = self.normalize_watchdog_url(u)
            conditions.append("location LIKE %s")
            params.append("%" + relative_url + "%")
            conditions.append("location LIKE %s")
            params.append("%" + u + "%")
        condition_str = " OR ".join(conditions)

        sql_query = f"""
            SELECT timestamp, type, message, variables, severity
            FROM watchdog
            WHERE ({condition_str}) AND severity <= 4
            ORDER BY timestamp DESC
            LIMIT 5;
        """
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
                    cursor.execute(sql_query, params)
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

    parser = argparse.ArgumentParser(
        description="Dual domain crawler with comparison functionality."
    )
    parser.add_argument("--reference", help="Starting URL for domain 1.")
    parser.add_argument("--test", required=True, help="Starting URL for domain 2.")
    parser.add_argument("--depth", type=int, default=4, help="Crawl depth.")
    parser.add_argument("--screenshots", action="store_true", help="Enable saving screenshots.")
    parser.add_argument("--lang", default="", help="Only process pages with <html lang=\'X\'> matching this language (e.g., \'en\').")
    parser.add_argument("--remove-selectors", default="", help="Comma-separated list of CSS selectors to remove before screenshot.")
    parser.add_argument("--reference-db", default="", help="MySQL connection string for the reference site (mysql://user:pass@host:port/dbname)")
    parser.add_argument("--test-db", default="", help="MySQL connection string for the test site (mysql://user:pass@host:port/dbname)")
    parser.add_argument("--limit-same-url-with-parameters", type=int, default=0,
                        help="Limit how many different requests to the same URL (including query parameters) are allowed.")
    parser.add_argument("--delay-before-capture", type=float, default=0,
                        help="Delay in seconds before capturing markup, text, and screenshots.")
    parser.add_argument("--concurrent-requests", dest="concurrent_requests",
                        type=int, default=8,
                        help="Maximum concurrent requests Scrapy should perform (Scrapy setting CONCURRENT_REQUESTS).")
    parser.add_argument("--exclude-paths", default="",
                        help="Comma-separated list of regex patterns; any URL matching a pattern will be skipped.")
    parser.add_argument("--exclude-links-inside-classes", dest="exclude_links_inside_classes", default="",
                        help=("Comma-separated CSS class names; any <a> tag that is "
                              "inside an element with one of these classes will be skipped."))
    args = parser.parse_args()
    # Ensure the attribute exists even if parser failed to create it for some reason
    if not hasattr(args, "exclude_links_inside_classes"):
        setattr(args, "exclude_links_inside_classes", "")

    process = CrawlerProcess(settings={"CONCURRENT_REQUESTS": args.concurrent_requests})
    process.crawl(
        DualDomainSpider,
        crawl_depth=args.depth,
        reference=args.reference,
        test=args.test,
        save_screenshots=args.screenshots,
        lang=args.lang,
        remove_selectors=args.remove_selectors,
        reference_db=args.reference_db,
        test_db=args.test_db,
        limit_same_url_with_parameters=args.limit_same_url_with_parameters,
        delay_before_capture=args.delay_before_capture,
        exclude_paths=args.exclude_paths,
        exclude_links_inside_classes=getattr(args, "exclude_links_inside_classes", "")
    )
    process.start()

