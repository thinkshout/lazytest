This crawls a test and reference domain using a headless browser (with javascript), saves html, text, and optional screenshots for both.
Allows for comparisons between test and reference.

## Output
* html (output/html)
* markdown based on the visible text from html (output/text)
* screenshots, optional (output/screenshots)
* log file in output\log.txt contains
  * timestamp
  * urls
  * http response code
  * load times (ttfb, dom_content_loaded,load_event,network_idle)
  * Browser console logs (javascript errors)
  * Drupal logs (watchdog)

## Run tests with

Both a test and reference site:
`python ./test.py --test=https://develop-site.pantheonsite.io --reference=https://test-site.pantheonsite.io --depth=1 --lang=en --screenshots --remove-selectors="#id,.class" --reference-db="mysql://[username]:[password]@[host]:[port]/[database name]"`

Just a test site (no reference):
`python ./test.py --test=https://develop-site.pantheonsite.io --depth=1 --lang=en --remove-selectors="#id,.class" --test-db="mysql://[username]:[password]@[host]:[port]/[database name]"`

```
--test
  Test domain
--reference
  Reference domain
--crawl_depth=2
  Number of links to follow consecutively
--same_page_with_url_parameters
  Save and follow the same page with different url parameters like search?page=2
--screenshots
  Capture screenshots. This will be slower since it'll also download images etc.
--target_lang=en
  Only save/follow specific languages
--remove-selectors="#id, .class"
  Remove elements before taking a screenshot (like a modal)
--reference-db="mysql://[username]:[password]@[host]:[port]/[database name]"
--test-db="mysql://[username]:[password]@[host]:[port]/[database name]"
  Database connection details for Drupal sites to get watchdog logs for specific pages.
 ```

## Run text diffs with
`diff -r output/text/test output/text/reference`

## Run screenshot diffs with
`npx reg-cli output/screenshots/test output/screenshots/reference output/screenshots/diff -R output/screenshots/diff.html`

## Todo/Ideas
* 