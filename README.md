# Slow Query Analyzer

WordPress plugin that finds slow and repeated MySQL queries and explains why they are slow. It only profiles your own admin session after you switch it on. Visitors are never affected.

## Install (once)

Download `slow-query-analyzer.zip` from the [latest release](https://github.com/didoivanov/slow-query-analyzer/releases/latest) and upload it via **Plugins → Add New → Upload Plugin**.

## Updates

After that, new versions appear under **Plugins** in wp-admin like any other plugin. Click **Update now**, or use **Check for updates** in the plugin's row to check right away. You can also enable auto-updates.

Repository is private? Add a fine-grained GitHub token with read-only *Contents* access under **Tools → Slow Queries → Settings**. You can also define `SQA_GITHUB_TOKEN` in `wp-config.php`.

## Releasing

Every push to `main` releases automatically:

1. The patch version is bumped (1.0.0 → 1.0.1) in the plugin header, `SQA_VERSION` and `readme.txt`.
2. The change is committed and tagged `vX.Y.Z`.
3. A GitHub release is published with `slow-query-analyzer.zip`.

- Add `[skip release]` to a commit message to push without releasing.
- For a minor or major bump, use **Actions → Release → Run workflow**.
- Changes that only touch `README.md` or `.github/` don't create a release.
