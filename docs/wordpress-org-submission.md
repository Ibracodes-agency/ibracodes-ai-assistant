# Submitting IbraCodes AI Assistant to WordPress.org

## Before submitting

1. `wp plugin check ibracodes-ai-assistant` on a site running the latest WordPress: zero errors. The two remaining warnings are the dev dotfiles (excluded from the zip) and the informational note about calling OpenAI directly, which readme.txt discloses under Third-party services.
2. `readme.txt`: `Stable tag` equals the plugin header version; `Tested up to` equals the current WordPress major.minor; `Contributors` is the wordpress.org username that will own the plugin.
3. Run the test sweep and the widget harness (see `tests/README.md`).
4. Build the zip: `wp dist-archive /path/to/ibracodes-ai-assistant /path/to/output/` (honours `.distignore`, so tests, docs, dotfiles and the translation build script stay out).

## Submitting

1. Log in at https://wordpress.org/plugins/developers/add/ with the owner account and upload the zip.
2. The review team answers by email, usually within a few days to a few weeks. Reply to every point from the same email address; each round restarts the queue.
3. Once approved, WordPress.org creates `https://plugins.svn.wordpress.org/ibracodes-ai-assistant` and emails the credentials.

## After approval: the SVN repository

The layout WordPress.org expects:

    assets/    icon-128x128.png, icon-256x256.png, banner-772x250.png, banner-1544x500.png, screenshot-1.png ... screenshot-4.png
    trunk/     the plugin files exactly as in the zip
    tags/      one folder per released version, copied from trunk

Screenshot numbers match the captions in `readme.txt`. Assets live outside trunk and are never part of the plugin download.

`bin/deploy-svn.sh 0.2.0 /path/to/assets` does all of it: builds the zip, checks out SVN, syncs trunk and assets, creates the tag, sets image mime types, shows `svn status`, and commits after you confirm. It needs `svn` (`brew install subversion`) and the `wp dist-archive` package.

## Releasing later versions

Bump the version in the plugin header, `WSA_VERSION`, `Stable tag`, and add a changelog entry; then run the deploy script with the new version. WordPress.org serves the tag named in `Stable tag`.
