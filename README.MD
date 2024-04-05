Goal:
A testing tool that is easy to set up, easy to use, easy to maintain.

Functionalities:
- Implemented as a Drupal module which provides a drush command.
- The drush command generates:
  - CSV output which can be imported into a spreadsheet (source, status code, url, message)
  - Error analysis (consolidated for quicker fixes)
- Various URL Providers supply urls to test
  - Content Types (10 old and 10 new nodes from each bundle)
  - Files (10 old and 10 new files)
  - Media (10 old and 10 new Media pages)
  - Menu (all menu items)
  - Routes (filtered list of all route urls)
  - Taxonomy (10 old and 10 new term pages from each vocabulary)
  - Users (10 old and 10 new users)
  - Views (all view urls)
  - Future ideas:
    - Google Analytics (top 100 pages)
    - Manual list

To Do:
- Add a way to do an initial run, show results, maybe with checkboxes for each line pre-checked, ability for future runs to exclude those urls with the same code and/or the same error message.
- Way to exclude certain urls
- Way to include certain urls
- Consider sitemap for url sources.
- Should I cache bust by clearing all cache at the start of the run or by including url parameters? Which is more reliable/faster?
- Add javascript console errors. Would have to use a headless browser like Puppeteer, Playwright or https://github.com/chrome-php/chrome. We should test speed and see if it makes sense to move our current url get logic over.
