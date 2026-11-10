# Iron Armada branding pack

Author: Josh Neufield (Archon Tech)

## Files

| File | Size | Goes where |
|---|---|---|
| `logo-iron-armada.png` | 930x232, transparent | Login/register page. Replaces `.logo_ogame` (was a 173x57 JPG) |
| `logo-archon-tech.png` | 945x232, transparent | Same page. Replaces `.logo_gf` (the GameForge logo, 274x58) |
| `login-background.jpg` / `login-background@2x.jpg` | 1404x844 / 2808x1688 | `#start` background on the login page (was `img/outgame/1867da5b5f8769b547bb91d88bb4f8.jpg`) |
| `ingame-background.jpg` | 3440x1440 | In-game backdrop (was `img/bg/background-large.jpg`, used by `resources/css/ingame/02base.css`) |
| `favicon.ico` | 16/32/48 | `public/favicon.ico` |
| `favicon-32.png` | 32x32 | `<link rel="icon">` |
| `icon-180.png`, `icon-512.png` | 180 / 512 | `apple-touch-icon` and general app icon |
| `text/*.txt` | n/a | Rules, legal, privacy, terms, contact (admin settings), welcome message, footer line |

The favicon is a tight crop of the hexagon. At 16px it is a blur of steel and amber, which is expected.

## Prompt for Claude Code (assets are already in the repo on branch branding/iron-armada)

```
Task: apply Iron Armada branding to this repo (NinjaInc/IronArmada). The asset pack is in branding/iron-armada-assets/ in this repo (already committed on the branch branding/iron-armada on GitHub). Fetch it, check out branding/iron-armada, merge or rebase onto my local main (my local main has an unpushed Reverb config fix; the asset folder is all new files so it should not conflict), and do the work on that branch. Read AGENTS.md first and follow it. Do not rename PHP namespaces or code identifiers.

A. Images. Copy them into public/img/iron-armada/ and change the references. Do not overwrite the original hashed files.
 1. logo-iron-armada.png replaces .logo_ogame in the outgame CSS. Resize the slot to about 260x65 with background-size: contain, no-repeat, keep it floated right (check the page).
 2. logo-archon-tech.png replaces .logo_gf (the GameForge logo). background-size: contain, background-position: left center, keep the 274x58 slot.
 3. login-background.jpg (use the @2x file via image-set if simple) replaces the #start and #start.partner-onet backgrounds.
 4. ingame-background.jpg replaces background-large.jpg in resources/css/ingame/02base.css.
 5. favicon.ico goes to public/favicon.ico. Add <link rel="icon"> (favicon-32.png) and apple-touch-icon (icon-180.png) to BOTH layouts (ingame/layouts/main.blade.php and outgame/layouts/main.blade.php). The outgame apple-touch-icon currently points to a file that does not exist.

B. Remove the side strip (the % / +40% promo banner on the right of the in-game screen).
 - It is the <div id="bannerSkyscrapercomponent"> block in resources/views/ingame/layouts/main.blade.php (around lines 1859-1867). It is a placeholder shop link (href="#TODO=shop"). Delete the block and public/img/banners/de0dadddb0285ba78b026ce18fc898.jpg.
 - The #banner_skyscraper JS handler is harmless; leave it. Check the page for layout gaps afterwards.
 - Also list any other GameForge/OGame promo or external links (for example the Facebook/Google+ icons in the outgame footer, other shop links). Report them and ask before removing.

C. Text.
 - Confirm <title> and APP_NAME show "Iron Armada".
 - In resources/lang/en/t_external.php, t_messages.php and t_ingame.php replace visible "OGame" text with "Iron Armada". Show me the list before applying. Many "ogame" occurrences in views are CSS classes, IDs and URLs; do not touch those.
 - Footer: use text/footer.txt in the outgame and in-game footers.
 - Welcome message: use text/welcome-message.txt for the registration welcome message (see app/GameMessages/WelcomeMessage.php and the lang strings).
 - Rules, legal, privacy, terms and contact: the keys are rules_content, legal_content, privacy_policy_content, terms_content, contact_content (SettingsService). Find how they are rendered (escaped or raw HTML) and set them from text/*.txt via the admin settings page or SettingsService, not hardcoded in views. Preserve the text exactly. contact.txt contains two placeholders ([Insert Discord Invite Link] and [Insert Admin Email]). Ask me for the values before saving it.

D. Verify. Run npm run build, restart the containers, open the login page and an in-game page and send me screenshots. Run the AGENTS.md toolchain (rector, cs, stan, tests). Commit with this trailer:
Co-Authored-By: Josh Neufield <ozeyeball@gmail.com>
Report what you changed, with file names.
```
