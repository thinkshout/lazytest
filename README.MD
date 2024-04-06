Goal:
A testing tool that is easy to set up, easy to use, easy to maintain.

Usage:
- drush lazytest:run

Functionalities:
- Implemented as a Drupal module which provides a drush command.
- The drush command generates:
  - CSV output which can be imported into a spreadsheet (source, status code, url, message)
  - Error analysis (consolidated for quicker fixes)
- Various URL Providers supply urls to test
  - Nodes (some old and new nodes from each Content Type)
  - Files (some old and new File Entities)
  - Media (some old and new Media Entities of each Media Type)
  - Menu (all menu items)
  - Routes (all route urls for custom modules)
  - Taxonomy (some old and new Terms from each Taxonomy Vocabulary)
  - Users (some old and new users)
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
