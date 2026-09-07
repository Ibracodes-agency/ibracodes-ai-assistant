# Publishing IbraCodes AI Assistant on WordPress.org

## First submission

1. `wp plugin check ibracodes-ai-assistant` on a site running the latest WordPress: zero errors. The remaining warnings are the dev dotfiles (excluded from the zip) and the informational note about calling OpenAI directly, which readme.txt discloses under Third-party services.
2. `readme.txt`: `Stable tag` equals the plugin header version and `WSA_VERSION`; `Tested up to` equals the current WordPress major.minor; `Contributors` is the wordpress.org username that owns the plugin. `bin/check-version.sh` confirms the versions agree.
3. Build the zip from the WordPress root: `wp dist-archive /path/to/ibracodes-ai-assistant /path/to/output/` (honours `.distignore`, so only the plugin file, readme.txt, uninstall.php, includes, assets and the compiled .mo are inside).
4. Upload the zip at https://wordpress.org/plugins/developers/add/ with the owner account. The review team answers by email; reply from the same address, every round restarts the queue.
5. Once approved, WordPress.org creates the SVN repository and emails the credentials.

## One-time setup after approval

Add two repository secrets on GitHub under Settings, Secrets and variables, Actions: `SVN_USERNAME` and `SVN_PASSWORD`, the wordpress.org login. Nothing is published until they exist.

## Releasing

Publishing is automated; a tag is the release:

    # 1. bump the version in three places (all must agree)
    #    ibracodes-ai-assistant.php: Version: and define('WSA_VERSION', ...)
    #    readme.txt: Stable tag: and a changelog entry
    # 2. verify locally
    bin/check-version.sh
    # 3. release
    git tag 0.2.0 && git push origin main --tags

The `deploy` workflow rejects the release if those versions disagree, then builds the plugin (honouring `.distignore`), commits it to SVN trunk, creates the matching SVN tag, and uploads the banners, icon and screenshots from `.wordpress-org/`.

Banner, icon, screenshot or `readme.txt` changes alone do not need a release: pushing them to `main` runs the `assets` workflow, which updates the listing in place.

Do not push a version tag before the SVN credentials are in place: the workflow would fail at the SVN step and the tag would have to be moved.

## The listing artwork

`.wordpress-org/` holds `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png` and `screenshot-1.png` to `screenshot-4.png`. Screenshot numbers match the captions in `readme.txt`. The assets are never part of the plugin download.
