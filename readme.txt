=== Signed Posts ===
Contributors: marc4
Tags: openpgp, did, signature, security, verification
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.0
Tested up to PHP: 8.3
Stable tag: 0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Signed Posts allows authors to sign posts, assuring content integrity. Signature verification proves post-signing alteration hasn't occurred.

== Description ==

Signed Posts allows authors to sign posts, assuring content integrity. Signature verification proves post-signing alteration hasn't occurred.

**Features:**

* **In-browser verification:** The signature verification is done on the client side (in the visitor's browser).
* **Methods:** OpenPGP (ASCII-armored detached signature) and DID (did:key, did:web) using Ed25519 detached JWS (b64=false).
* **Source of trust:** For OpenPGP, the author specifies the URL of their public key in their profile. For DID, the author sets their DID (did:key or did:web). For did:web, the plugin fetches `https://<host>/.well-known/did.json`.
* **Status block:** An informative block is automatically added to the end of each signed article, showing the verification status (valid, invalid, or error).
* **Author badge:** The author name in posts is enhanced with an icon and KeyID/fingerprint text.

== Installation ==

1. Go to **Plugins > Add New Plugin**.
2. Search for **Signed Posts**.
3. Install and activate the **Signed Posts** plugin.

== Frequently Asked Questions ==

**Q: How do I get the content of my post to sign it?**
A: Once you’ve finished your post, click the three dots in the top-right corner of the Gutenberg editor. When the options menu opens, select “Copy all blocks.” That is the content you should sign.

**Q: Where can I host my OpenPGP public key?**
A: You can host it on any service that offers direct links and allows CORS (Cross-Origin Resource Sharing) access.

**Q: What happens if the signature isn't valid?**
A: The plugin will display a warning message indicating that the signature doesn't match the content or the public key, which can be a sign of content tampering.

**Q: Does the plugin affect my site's performance?**
A: The impact on the server is minimal, as the verification is performed entirely in the visitor's browser. The only additional resource is the download of the public key, which is usually very small.

**Q: What format should I use to sign with DID?**  
A: Use Compact JWS (detached) with `{"alg":"EdDSA","b64":false,"crit":["b64"],"kid":"<your did#key>"}` and sign the canonicalized post content (same text you would sign with OpenPGP).

**Q: Where do I set my DID?**  
A: In your User Profile, in the "Decentralized Identifiers (DID)" field. For `did:web`, ensure your `did.json` is hosted at `https://<host>/.well-known/did.json`.

== Source Code and Libraries ==

**OpenPGP.js**
* **Version:** 6.2.2
* **License:** LGPL-3.0-or-later
* **Public Source Code:** https://github.com/openpgpjs/openpgpjs

**Web Crypto API**
* Used to verify Ed25519 signatures for DID.

== Changelog ==

= [0.4] - 2025-10-08 =
* DID support added: did:key and did:web with Ed25519 JWS (detached).
* Method selector per post.
* DID field in user profile.
* Uninstall options extended to remove DID and method meta.
* Maintains full backward compatibility with OpenPGP flow.

= [0.3] - 2025-10-03 =
* Author badge with icon and fingerprint text linked to the verification result.
* OpenPGP updated to 6.2.2.
* Some corrections.

= [0.1] - 2025-09-23 =
* First version.
