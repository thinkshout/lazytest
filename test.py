import os
import re
import sys
import hashlib
from urllib.parse import urlparse, urljoin

import scrapy
from scrapy.crawler import CrawlerProcess
from scrapy import signals
from scrapy.exceptions import DontCloseSpider

import pypandoc  # For HTML-to-Markdown conversion
from scrapy_playwright.page import PageMethod  # For intercepting requests

from bs4 import BeautifulSoup  # For cleaning HTML


class DualDomainSpider(scrapy.Spider):
    name = "dual_domain_spider"

    custom_settings = {
        # Use Playwright for HTTP and HTTPS
        "DOWNLOAD_HANDLERS": {
            "http": "scrapy_playwright.handler.ScrapyPlaywrightDownloadHandler",
            "https": "scrapy_playwright.handler.ScrapyPlaywrightDownloadHandler",
        },
        "TWISTED_REACTOR": "twisted.internet.asyncioreactor.AsyncioSelectorReactor",
        "PLAYWRIGHT_LAUNCH_OPTIONS": {"headless": True},
        "ROBOTSTXT_OBEY": False,
        # Speed and AutoThrottle settings:
        "CONCURRENT_REQUESTS": 8,
        "CONCURRENT_REQUESTS_PER_DOMAIN": 8,
        "COOKIES_ENABLED": False,
        "DOWNLOAD_DELAY": 0,
        "DOWNLOAD_TIMEOUT": 60,
        "LOG_LEVEL": "INFO",
        "AUTOTHROTTLE_ENABLED": True,
        "AUTOTHROTTLE_START_DELAY": 2,
        "AUTOTHROTTLE_MAX_DELAY": 60,
        "AUTOTHROTTLE_TARGET_CONCURRENCY": 1.0,
        "AUTOTHROTTLE_DEBUG": False,
    }

    def __init__(self, crawl_depth, url1, url2, save_screenshots="false", *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.crawl_depth = int(crawl_depth)
        self.start_url1 = url1
        self.url2 = url2
        # Convert the save_screenshots parameter to boolean.
        self.save_screenshots = str(save_screenshots).lower() in ("true", "1", "yes")

        # Phase 1 pages (url1) follow links; phase 2 pages (url2) do not.
        self.phase = 1
        self.discovered_paths = set()

        # Determine domains (credentials stripped).
        self.domain1 = self.get_domain(url1)
        self.domain2 = self.get_domain(url2)

        # Create output folders for each domain under output/html, output/text, and output/screenshots.
        for sub in ["html", "text", "screenshots"]:
            for domain in [self.domain1, self.domain2]:
                os.makedirs(os.path.join("output", sub, domain), exist_ok=True)

        # Extract basic‑auth credentials if provided.
        self.auth1 = self.get_auth_info(url1)
        self.auth2 = self.get_auth_info(url2)

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
                PageMethod("route", "**/*", DualDomainSpider.block_unwanted_resources)
            ],
        }
        # If screenshot saving is enabled, include the playwright page object.
        if self.save_screenshots:
            meta["playwright_include_page"] = True
        if auth:
            meta["playwright_context_kwargs"] = {"http_credentials": auth}
        return meta

    def get_output_filepath(self, domain, url, subfolder, default_ext):
        """
        Build an output filepath as:
        output/<subfolder>/<domain>/<sanitized relative path + default_ext>
        """
        parsed = urlparse(url)
        rel_path = parsed.path
        if rel_path.endswith("/") or not rel_path:
            rel_path += "index" + default_ext
        else:
            if not os.path.splitext(rel_path)[1]:
                rel_path += default_ext
            else:
                rel_path = os.path.splitext(rel_path)[0] + default_ext
        rel_path = self.sanitize_path(rel_path.lstrip("/"))
        return os.path.join("output", subfolder, domain, rel_path)

    def sanitize_path(self, path):
        return re.sub(r'[<>:"/\\|?*]', "_", path)

    def start_requests(self):
        yield scrapy.Request(
            url=self.start_url1,
            callback=self.parse_page,
            meta=self.build_meta(phase=1, depth=0, auth=self.auth1),
            errback=self.errback,
        )

    def errback(self, failure):
        self.logger.error(repr(failure))

    async def parse_page(self, response):
        phase = response.meta.get("phase", 1)
        current_depth = response.meta.get("depth", 0)
        base_domain = self.domain1 if phase == 1 else self.domain2

        # Save HTML and Markdown.
        self.save_html(response, base_domain)
        self.save_markdown(response, base_domain)

        # If screenshots are enabled, save a screenshot.
        if self.save_screenshots:
            await self.save_screenshot(response, base_domain)

        if phase == 1:
            # Immediately schedule the same page from url2.
            relative = self.get_relative_path(response.url)
            abs_url2 = urljoin(self.url2, relative)
            yield scrapy.Request(
                url=abs_url2,
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
                    self.discovered_paths.add(self.get_relative_path(abs_url))
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

    def get_relative_path(self, url):
        parsed = urlparse(url)
        path = parsed.path or "/"
        if parsed.query:
            query_hash = hashlib.md5(parsed.query.encode("utf-8")).hexdigest()[:8]
            path = path + ("index_" if path.endswith("/") else "_") + query_hash
        return path

    def save_html(self, response, base_domain):
        file_path = self.get_output_filepath(base_domain, response.url, "html", ".html")
        os.makedirs(os.path.dirname(file_path), exist_ok=True)
        with open(file_path, "wb") as f:
            f.write(response.body)
        self.logger.info(f"Saved HTML: {file_path}")

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
        self.logger.info(f"Saved Markdown: {file_path}")

    async def save_screenshot(self, response, base_domain):
        page = response.meta.get("playwright_page")
        if not page:
            self.logger.error("No playwright_page in meta for screenshot!")
            return
        file_path = self.get_output_filepath(base_domain, response.url, "screenshots", ".png")
        os.makedirs(os.path.dirname(file_path), exist_ok=True)
        await page.screenshot(path=file_path, full_page=True)
        self.logger.info(f"Saved screenshot: {file_path}")


if __name__ == "__main__":
    if len(sys.argv) < 4:
        print("Usage: python script.py <crawl_depth> <url1> <url2> [save_screenshots]")
        sys.exit(1)
    crawl_depth, url1, url2 = sys.argv[1:4]
    save_screenshots = sys.argv[4] if len(sys.argv) >= 5 else "false"
    process = CrawlerProcess()
    process.crawl(DualDomainSpider, crawl_depth=crawl_depth, url1=url1, url2=url2, save_screenshots=save_screenshots)
    process.start()