# Signed Posts
Allows authors to sign posts, assuring content integrity. Signature verification proves post-signing alteration hasn’t occurred.

## Features:

* **In-browser verification:** The signature verification is done on the client side (in the visitor's browser).
* **Methods:** OpenPGP (ASCII-armored detached signature) and DID (did:key, did:web) using Ed25519 detached JWS (b64=false).
* **Source of trust:** For OpenPGP, the author specifies the URL of their public key in their profile. For DID, the author sets their DID (did:key or did:web). For did:web, the plugin fetches `https://<host>/.well-known/did.json`.
* **Status block:** An informative block is automatically added to the end of each signed article, showing the verification status (valid, invalid, or error).
* **Author badge:** The author name in posts is enhanced with an icon and KeyID/fingerprint text.

## Frequently Asked Questions

### How do I get the content of my post to sign it?
Once you’ve finished your post, click the three dots in the top-right corner of the Gutenberg editor. When the options menu opens, select “Copy all blocks.” That is the content you should sign.

### Where can I host my OpenPGP public key?
You can host it on any service that offers direct links and allows CORS (Cross-Origin Resource Sharing) access.

### What happens if the signature isn't valid?
The plugin will display a warning message indicating that the signature doesn't match the content or the public key, which can be a sign of content tampering.

### Does the plugin affect my site's performance?
The impact on the server is minimal, as the verification is performed entirely in the visitor's browser. The only additional resource is the download of the public key, which is usually very small.

### What format should I use to sign with DID?
Use Compact JWS (detached) with `{"alg":"EdDSA","b64":false,"crit":["b64"],"kid":"<your did#key>"}` and sign the canonicalized post content (same text you would sign with OpenPGP).

### Where do I set my DID?
In your User Profile, in the "Decentralized Identifiers (DID)" field. For `did:web`, ensure your `did.json` is hosted at `https://<host>/.well-known/did.json`.

## Source Code and Libraries
OpenPGP.js - The minified library (`openpgp.min.js`) is included locally for client-side OpenPGP verification.

* Version: 6.2.2
* License: LGPL-3.0-or-later
* Public Source Code (Non-compiled): [https://github.com/openpgpjs/openpgpjs](https://github.com/openpgpjs/openpgpjs)

Web Crypto API - Used to verify Ed25519 signatures for DID.

## Resources
WordPress Plugin Repository: [https://wordpress.org/plugins/signed-posts/](https://wordpress.org/plugins/signed-posts/)

## Requirements
- WordPress 6.0+
- PHP 7.0+
- License: GPLv2 or later
