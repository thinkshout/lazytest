# Include way to do image diffs, maybe just reuse backstopJS functionality for that but use our output files.
# Add logging for Drupal errors. How do we get those? Terminus? Database call?

import os
import re
import sys
import logging
logging.getLogger("pypandoc").setLevel(logging.INFO)
import json
import csv
import datetime
from urllib.parse import urlparse, urljoin, urlunparse

import scrapy
from scrapy.crawler import CrawlerProcess
from scrapy import signals
from scrapy.exceptions import DontCloseSpider

import pypandoc  # For HTML-to-Markdown conversion
from scrapy_playwright.page import PageMethod  # For intercepting requests

from bs4 import BeautifulSoup  # For cleaning HTML and checking language

class DualDomainSpider(scrapy.Spider):
    name = "dual_domain_spider"

    custom_settings = {
        # Use Playwright for HTTP and HTTPS.
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
            ]
        },
        "ROBOTSTXT_OBEY": False,
        # Speed and AutoThrottle settings:
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
                 same_page_with_url_parameters=False, lang="", *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.crawl_depth = int(crawl_depth)
        self.start_reference = reference
        self.test = test
        self.save_screenshots = str(save_screenshots).lower() in ("true", "1", "yes")
        self.same_page_with_url_parameters = same_page_with_url_parameters
        self.target_lang = lang.lower() if lang else None

        # Phase 1 pages (reference) follow links; phase 2 pages (test) do not.
        self.phase = 1
        self.discovered_paths = set()

        # Determine domains (credentials stripped).
        self.domain1 = self.get_domain(reference)
        self.domain2 = self.get_domain(test)

        # For html outputs, use fixed folders: 'reference' and 'test'
        os.makedirs(os.path.join("output", "html", "reference"), exist_ok=True)
        os.makedirs(os.path.join("output", "html", "test"), exist_ok=True)

        # For text outputs, use fixed folders: 'reference' and 'test'
        os.makedirs(os.path.join("output", "text", "reference"), exist_ok=True)
        os.makedirs(os.path.join("output", "text", "test"), exist_ok=True)

        # For screenshots, use fixed directories: 'reference' and 'test'
        os.makedirs(os.path.join("output", "screenshots", "reference"), exist_ok=True)
        os.makedirs(os.path.join("output", "screenshots", "test"), exist_ok=True)

        self.auth1 = self.get_auth_info(reference)
        self.auth2 = self.get_auth_info(test)

        # Sets for deduplication.
        self.seen_normalized_1 = set()
        self.seen_normalized_2 = set()

        # For logging load metrics to CSV.
        self.log_file_path = os.path.join("output", "log.txt")
        self.log_handle = None  # Will be opened in spider_opened
        self.log_writer = None

    @classmethod
    def from_crawler(cls, crawler, *args, **kwargs):
        spider = super().from_crawler(crawler, *args, **kwargs)
        crawler.signals.connect(spider.spider_opened, signal=signals.spider_opened)
        crawler.signals.connect(spider.spider_closed, signal=signals.spider_closed)
        return spider

    def spider_opened(self, spider):
        # Ensure output directory exists.
        os.makedirs("output", exist_ok=True)
        self.log_handle = open(self.log_file_path, "w", newline="", encoding="utf-8")
        self.log_writer = csv.writer(self.log_handle)
        # Write CSV header.
        self.log_writer.writerow(["timestamp", "url", "response_code", "ttfb (ms)", "dom_content_loaded (ms)", "load_event (ms)", "network_idle (ms)"])

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

    @staticmethod
    async def block_unwanted_resources(route, request):
        # Allow only "document" and "script" resources.
        if request.resource_type not in {"document", "script"}:
            await route.abort()
        else:
            await route.continue_()

    def build_meta(self, phase, depth, auth=None):
        meta = {
            "playwright": True,
            "phase": phase,
            "depth": depth,
            "playwright_page_methods": [
                PageMethod("route", "**/*", DualDomainSpider.block_unwanted_resources),
            ],
            # To capture performance metrics, we ensure the page is included.
            "playwright_include_page": True,
        }
        if self.save_screenshots:
            meta["playwright_include_page"] = True
        if auth:
            meta["playwright_context_kwargs"] = {"http_credentials": auth}
        return meta

    def get_output_filepath(self, domain, url, subfolder, default_ext):
        """
        Build a file path for saving output files.
        """
        relative = self.get_relative_path(url)
        sanitized = self.sanitize_path(relative)
        folder = "reference" if domain == self.domain1 else "test"
        return os.path.join("output", subfolder, folder, sanitized + default_ext)

    def sanitize_path(self, path):
        return re.sub(r'[<>:"/\\|?*]', "_", path)

    def get_relative_path(self, url):
        """
        Build a relative file path from the URL.
        This version appends the query string (if any) to the URL's path.
        For example:
          /search?foo=bar becomes search_foo=bar
        """
        parsed = urlparse(url)
        path = parsed.path or "/"
        path = path.lstrip("/")
        if parsed.query:
            path += "_" + parsed.query
        return path

    def get_request_relative_url(self, url):
        """Return the exact relative URL (path plus query) for the request."""
        parsed = urlparse(url)
        relative = parsed.path
        if parsed.query:
            relative += "?" + parsed.query
        return relative

    def normalize_url(self, url):
        """
        Normalize the URL for deduplication.
        If self.same_page_with_url_parameters is True, return path + query.
        Otherwise, return just the path.
        """
        parsed = urlparse(url)
        if self.same_page_with_url_parameters:
            relative = parsed.path
            if parsed.query:
                relative += "?" + parsed.query
            return relative
        else:
            return parsed.path

    def start_requests(self):
        yield scrapy.Request(
            url=self.start_reference,
            callback=self.parse_page,
            meta=self.build_meta(phase=1, depth=0, auth=self.auth1),
            errback=self.errback,
        )

    def errback(self, failure):
        request = failure.request
        self.logger.error(f"Request failed: {request.url}. Error: {failure}")

    async def parse_page(self, response):
        self.logger.info(f"Processing URL: {response.url}")
        phase = response.meta.get("phase", 1)
        current_depth = response.meta.get("depth", 0)
        base_domain = self.domain1 if phase == 1 else self.domain2

        # Check language if target_lang is set.
        if self.target_lang:
            soup = BeautifulSoup(response.text, "html.parser")
            html_tag = soup.find("html")
            page_lang = html_tag.get("lang", "").lower() if html_tag else ""
            if page_lang != self.target_lang:
                self.logger.info(f"Skipping page with lang '{page_lang}' (target: {self.target_lang}). URL: {response.url}")
                return

        # Deduplicate based on normalized URL.
        normalized = self.normalize_url(response.url)
        if phase == 1:
            if normalized in self.seen_normalized_1:
                self.logger.info(f"Skipping duplicate phase 1 URL: {normalized}")
                return
            self.seen_normalized_1.add(normalized)
        else:
            if normalized in self.seen_normalized_2:
                self.logger.info(f"Skipping duplicate phase 2 URL: {normalized}")
                return
            self.seen_normalized_2.add(normalized)

        # Capture load-time metrics if a Playwright page is available.
        metrics = {"ttfb": None, "dom_content_loaded": None, "load_event": None, "network_idle": None}
        if "playwright_page" in response.meta:
            page = response.meta["playwright_page"]
            try:
                # Use the performance.timing API to capture various load events.
                performance_timing = await page.evaluate("() => JSON.stringify(window.performance.timing)")
                performance_timing = json.loads(performance_timing)
                navigation_start = performance_timing.get("navigationStart", 0)
                response_start = performance_timing.get("responseStart", 0)
                dom_content_loaded = performance_timing.get("domContentLoadedEventEnd", 0)
                load_event = performance_timing.get("loadEventEnd", 0)
                ttfb = response_start - navigation_start
                dom_time = dom_content_loaded - navigation_start
                load_time = load_event - navigation_start
                # Wait for network idle and then get the current performance.now() value.
                await page.wait_for_load_state("networkidle")
                network_idle = await page.evaluate("() => performance.now()")
                metrics = {
                    "ttfb": ttfb,
                    "dom_content_loaded": dom_time,
                    "load_event": load_time,
                    "network_idle": network_idle,
                }
            except Exception as e:
                self.logger.error(f"Error capturing performance metrics for {response.url}: {e}")

        # Log the metrics (CSV columns: timestamp, url, response code, ttfb, dom_content_loaded, load_event, network_idle).
        self.log_load_metrics(response.url, response.status, metrics)

        # Save HTML and Markdown.
        self.save_html(response, base_domain)
        self.save_markdown(response, base_domain)

        # If screenshots are enabled, save a screenshot (this will close the page).
        if self.save_screenshots:
            await self.save_screenshot(response, base_domain)

        self.log_queue_size()

        if "playwright_page" in response.meta and not self.save_screenshots:
            await response.meta["playwright_page"].close()

        if phase == 1:
            # Schedule the same page from test using the exact relative URL.
            relative_request = self.get_request_relative_url(response.url)
            abs_test = urljoin(self.test, relative_request)
            yield scrapy.Request(
                url=abs_test,
                callback=self.parse_page,
                meta=self.build_meta(phase=2, depth=current_depth, auth=self.auth2),
                errback=self.errback,
                dont_filter=True,
            )
            # Follow links if within crawl depth.
            if current_depth < self.crawl_depth:
                for link in response.css("a::attr(href)").getall():
                    if link.lower().startswith("javascript:") or link.lower().startswith("mailto:"):
                        continue
                    abs_url = response.urljoin(link)
                    if not self.is_html_url(abs_url):
                        continue
                    parsed = urlparse(abs_url)
                    if parsed.hostname != self.domain1:
                        continue
                    yield scrapy.Request(
                        url=abs_url,
                        callback=self.parse_page,
                        meta=self.build_meta(phase=1, depth=current_depth + 1, auth=self.auth1),
                        errback=self.errback,
                    )

    def is_html_url(self, url):
        non_html_ext = (
            ".jpg", ".jpeg", ".png", ".gif", ".svg", ".css",
            ".js", ".pdf", ".mp4", ".mp3", ".zip", ".rar"
        )
        parsed = urlparse(url)
        return not any(parsed.path.lower().endswith(ext) for ext in non_html_ext)

    def save_html(self, response, base_domain):
        file_path = self.get_output_filepath(base_domain, response.url, "html", ".html")
        os.makedirs(os.path.dirname(file_path), exist_ok=True)
        with open(file_path, "wb") as f:
            f.write(response.body)

    def clean_html(self, html):
        soup = BeautifulSoup(html, "html.parser")
        for tag in soup.find_all(['script', 'style', 'img']):
            tag.decompose()
        # Recursively unwrap all <div> tags.
        div_tags = soup.find_all('div')
        while div_tags:
            for tag in div_tags:
                tag.unwrap()
            div_tags = soup.find_all('div')
        for tag in soup.find_all():
            tag.attrs = {}
        return str(soup)

    def save_markdown(self, response, base_domain):
        file_path = self.get_output_filepath(base_domain, response.url, "text", ".md")
        os.makedirs(os.path.dirname(file_path), exist_ok=True)
        try:
            cleaned_html = self.clean_html(response.text)
            markdown_text = pypandoc.convert_text(cleaned_html, 'md', format='html')
            markdown_text = re.sub(r'</?div>', '', markdown_text)
        except Exception as e:
            self.logger.error(f"Pandoc conversion failed for {response.url}: {e}")
            markdown_text = "Conversion failed."
        with open(file_path, "w", encoding="utf-8") as f:
            f.write(markdown_text)

    async def save_screenshot(self, response, base_domain):
        page = response.meta.get("playwright_page")
        if not page:
            self.logger.error("No playwright_page in meta for screenshot!")
            return
        file_path = self.get_output_filepath(base_domain, response.url, "screenshots", ".png")
        os.makedirs(os.path.dirname(file_path), exist_ok=True)
        await page.screenshot(path=file_path, full_page=True)
        self.logger.info(f"Saved screenshot: {file_path}")
        # Explicitly close the page to free up the concurrency slot.
        await page.close()

    def log_load_metrics(self, url, response_code, metrics):
        if self.log_writer:
            timestamp = datetime.datetime.now().isoformat()
            # Round each metric to the nearest whole number (milliseconds)
            ttfb = round(metrics.get("ttfb", 0))
            dom_content_loaded = round(metrics.get("dom_content_loaded", 0))
            load_event = round(metrics.get("load_event", 0))
            network_idle = round(metrics.get("network_idle", 0))

            self.log_writer.writerow([
                timestamp,
                url,
                response_code,
                ttfb,
                dom_content_loaded,
                load_event,
                network_idle,
            ])
            self.log_handle.flush()

    def log_queue_size(self):
        enqueued = self.crawler.stats.get_value("scheduler/enqueued")
        dequeued = self.crawler.stats.get_value("scheduler/dequeued")
        remaining = enqueued - dequeued if enqueued is not None and dequeued is not None else "unknown"
        self.logger.info(f"Queue stats: enqueued={enqueued}, dequeued={dequeued}, remaining={remaining}")

if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(description="Dual domain crawler with comparison functionality.")
    parser.add_argument("--reference", required=True, help="Starting URL for domain 1.")
    parser.add_argument("--test", required=True, help="Starting URL for domain 2.")
    parser.add_argument("--depth", type=int, default=2, help="Crawl depth.")
    parser.add_argument("--screenshots", action="store_true", help="Enable saving screenshots.")
    parser.add_argument("--same-page-with-url-parameters", action="store_true",
                        help="Treat pages with different query parameters as distinct.")
    parser.add_argument("--lang", default="", help="Only process pages with <html lang='X'> matching this language (e.g., 'en').")
    args = parser.parse_args()

    process = CrawlerProcess()
    process.crawl(DualDomainSpider,
                  crawl_depth=args.depth,
                  reference=args.reference,
                  test=args.test,
                  save_screenshots=str(args.screenshots).lower(),
                  same_page_with_url_parameters=args.same_page_with_url_parameters,
                  lang=args.lang)
    process.start()