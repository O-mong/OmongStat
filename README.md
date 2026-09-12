<div align="center">

# OmongStat

**Your WordPress traffic, in your WordPress dashboard.**

Pageviews · Visitors · Referrers · Devices

[Installation](#installation) · [First visit](#check-your-first-visit) · [Settings](#settings)

</div>

---

OmongStat is a traffic analytics plugin for WordPress. It stores visit data in your WordPress database and displays statistics in the **Analytics** admin menu.

## Requirements

- A WordPress site running **PHP 8.0 or later**.
- Administrator access to install plugins.
- The built **`omongstat.zip`** installation file.

Node.js and Python are only needed to build the ZIP from source. They are not required on your WordPress server.

## Installation

1. Sign in to your WordPress dashboard.
2. Open **Plugins → Add New Plugin → Upload Plugin**.
3. Choose **`omongstat.zip`** and click **Install Now**.
4. Click **Activate Plugin**.
5. Open **Analytics** in the admin sidebar.

OmongStat creates its database table automatically when activated.

<details>
<summary><strong>Need to build the installation ZIP?</strong></summary>

On your development computer, install Node.js 22.12 or later, npm, Python 3, and Bash. Run these commands from the repository root:

```sh
npm ci
bash build-zip.sh
```

Upload the generated **`dist/omongstat.zip`** to WordPress. The script builds the admin dashboard and packages the plugin under an `omongstat/` folder.

The repository's source ZIP is not a WordPress installation package.

</details>

## Check your first visit

1. Clear your WordPress page cache and CDN cache, if enabled.
2. Open a public page in a private browser window while logged out.
3. Return to **Analytics** and click **Refresh**.
4. Check **Today’s pageviews** and **Recent events**.

> Visits from logged-in administrators are excluded by default. Use a logged-out window to test collection.

## Settings

Open **Analytics → Collection and data settings**.

| Setting | Default |
| --- | --- |
| Exclude visits from logged-in administrators | Enabled |
| Delete all analytics data when the plugin is uninstalled | Disabled |

Deactivating the plugin keeps your data. Uninstalling also keeps it unless you enable the deletion setting.

## Installation help

| Issue | What to check |
| --- | --- |
| WordPress cannot find a valid plugin | Upload the built `omongstat.zip`, not the repository source archive. |
| The admin build is missing | Rebuild the ZIP with `bash build-zip.sh` and upload it again. |
| No visits appear | Test while logged out, then clear your page cache and any optimized JavaScript cache. |
| Requests fail behind a cache or CDN | Exclude `/wp-json/` and requests with `?rest_route=` from caching. |

For Docker deployment, log imports, proxy configuration, and development commands, see [Development and operations](docs/DEVELOPMENT.md).
