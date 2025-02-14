This crawls a test and reference domain, saves html, text, and optional screenshots for both.
Allows for comparisons between test and reference.

## Output
* html is saved in output/html
* markup based on the visible text from html is saved in output/text
* screenshots (when enabled) are saved in output/screenshots
* urls, load times and http response codes are saved in output\log.txt

## Run tests with
`python ./test.py --test=https://develop-site.pantheonsite.io --reference=https://test-site.pantheonsite.io --depth=1 --lang=en --screenshots`
--test
  Test domain
--reference
  Reference domain
--crawl_depth=2
  Number of links to follow consecutively
--same_page_with_url_parameters
  Save and follow the same page with different url parameters like search?page=2
--screenshots
  Capture screenshots
--target_lang=en
  Only save/follow specific languages
--remove-selectors="#id, .class"
  Remove elements before taking a screenshot (like a modal)

## Run text diffs with
`diff -r output/text/test output/text/reference`

## Run screenshot diffs with
`npx reg-cli output/screenshots/test output/screenshots/reference output/screenshots/diff -R output/screenshots/diff.html`

## Ideas
* Add logging for Drupal errors. How do we get those? Terminus? Database call?
* Allow a single url for when we just want to crawl to look for certain tags in the rendered html for example.