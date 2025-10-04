## Description
Signed Posts allows authors to sign posts, assuring content integrity. Signature verification proves post-signing alteration hasn’t occurred.

## Features:

* **In-browser verification:** The signature verification is done on the client side (in the visitor's browser) using the OpenPGP.js library. This makes the process fast and doesn't add load to the server.
* **Source of trust:** The author specifies the URL of their public key in their user profile. This key is the source of trust for verification. It's recommended to host the key on an external service allows CORS (Cross-Origin Resource Sharing) access.
* **Status block:** An informative block is automatically added to the end of each signed article, showing the verification status (valid, invalid, or error).
* **Author badge:** The author name in posts is enhanced with an icon and fingerprint.

## Source Code and Libraries
OpenPGP.js - The minified library (`openpgp.min.js`) is included locally for client-side OpenPGP verification.

* **Version:** 6.2.2
* **License:** LGPL-3.0-or-later
* **Public Source Code (Non-compiled):** [https://github.com/openpgpjs/openpgpjs](https://github.com/openpgpjs/openpgpjs)

## Frequently Asked Questions

### How do I get the content of my post to sign it?
A: Once you’ve finished your post, click the three dots in the top-right corner of the Gutenberg editor. When the options menu opens, select “Copy all blocks.” That is the content you should sign.

### Where can I host my OpenPGP public key?
A: You can host it on any service that offers direct links and allows CORS (Cross-Origin Resource Sharing) access.

### What happens if the signature isn't valid?
The plugin will display a warning message indicating that the signature doesn't match the content or the public key, which can be a sign of content tampering.

### Does the plugin affect my site's performance?
The impact on the server is minimal, as the verification is performed entirely in the visitor's browser. The only additional resource is the download of the public key, which is usually very small.

## Resources

WordPress Plugin Repository: [https://wordpress.org/plugins/clear-internal-search-button/](https://wordpress.org/plugins/signed-posts/)

## Requirements

- WordPress 6.0+
- PHP 7.0+
- License: GPLv2 or later
