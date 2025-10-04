(function() {
    'use strict';

    /**
     * If the author badge ended up inside an <a> (because the theme wrapped the author text), 
     * move the badge node to immediately AFTER that <a>, so only the author name remains linked.
     */
    function detachBadgesFromLinks() {
        var badges = document.querySelectorAll('.wppgps-author-badge');
        if (!badges || !badges.length) return;

        badges.forEach(function(badge) {
            try {
                var anchor = badge.closest('a');
                if (anchor && anchor.contains(badge) && anchor.parentNode) {
                    if (anchor.nextSibling) {
                        anchor.parentNode.insertBefore(badge, anchor.nextSibling);
                    } else {
                        anchor.parentNode.appendChild(badge);
                    }
                }
            } catch (e) {
                // Cosmetic only
            }
        });
    }

    /**
     * Build the badge HTML string that will replace the token on the page.
     */
    function wppgpsBuildBadgeHTML(postId) {
        return '' +
        '<span class="wppgps-author-badge" data-post-id="' + String(postId) + '">' +
            '<span class="dashicons dashicons-shield wppgps-author-shield wppgps-author-shield--pending" aria-hidden="true"></span>' +
            '<span class="wppgps-author-fp" aria-live="polite" aria-atomic="true" hidden>' +
                '<span class="wppgps-author-fp-text">Signed - Fingerprint: </span>' +
                '<span class="wppgps-author-fp-value">•••• •••• •••• ••••</span>' +
            '</span>' +
        '</span>';
    }

    /**
     * Safely replace occurrences of the token in TEXT NODES only.
     * This avoids breaking markup or attributes such as title/href.
     */
    function wppgpsReplaceBadgeTokens(postId, token) {
        if (!token) return;

        var walker = document.createTreeWalker(
            document.body,
            NodeFilter.SHOW_TEXT,
            {
                acceptNode: function(node) {
                    // Skip empty/whitespace-only text nodes quickly
                    if (!node.nodeValue || node.nodeValue.indexOf(token) === -1) {
                        return NodeFilter.FILTER_SKIP;
                    }
                    return NodeFilter.FILTER_ACCEPT;
                }
            },
            false
        );

        var textNodes = [];
        var current;
        while ((current = walker.nextNode())) {
            textNodes.push(current);
        }

        // Replace token in each collected text node
        for (var i = 0; i < textNodes.length; i++) {
            var node = textNodes[i];
            var value = node.nodeValue;
            var idx;

            // Replace ALL occurrences in this text node (there could be more than one)
            while ((idx = value.indexOf(token)) !== -1) {
                var before = value.slice(0, idx);
                var after  = value.slice(idx + token.length);

                // Update current text node to "before"
                node.nodeValue = before;

                // Insert badge element right after this text node
                var tmp = document.createElement('span');
                tmp.innerHTML = wppgpsBuildBadgeHTML(postId);
                var badgeEl = tmp.firstChild;

                if (node.parentNode) {
                    node.parentNode.insertBefore(badgeEl, node.nextSibling);
                    // Insert the remaining text after the badge
                    var afterNode = document.createTextNode(after);
                    node.parentNode.insertBefore(afterNode, badgeEl.nextSibling);

                    // Continue processing the rest of the same original "value"
                    // by moving the cursor to the new "afterNode"
                    node = afterNode;
                    value = afterNode.nodeValue;
                } else {
                    // Fallback: if somehow parent is missing, break to avoid errors
                    break;
                }
            }
        }
    }

    /**
     * Format a hexadecimal key ID into 4-char uppercase blocks.
     */
    function formatKeyId(hexId) {
        if (!hexId) return '';
        var s = String(hexId).toUpperCase().replace(/[^0-9A-F]/g, '');
        if (s.length !== 16) return s;
        return s.slice(0, 4) + ' ' + s.slice(4, 8) + ' ' + s.slice(8, 12) + ' ' + s.slice(12, 16);
    }

    /**
     * Update the bottom status card style + message.
     */
    function updateStatus(resultDiv, statusClass, msg) {
        if (!resultDiv) return;
        resultDiv.className = 'pgp-status-container ' + statusClass;
        resultDiv.innerHTML = '<span class="pgp-status-text">' + msg + '</span>';
    }

    // Badge visual helpers
    function setAuthorBadgePending(authorShield, authorFpWrapper) {
        if (!authorShield) return;
        authorShield.classList.remove('wppgps-author-shield--valid', 'wppgps-author-shield--invalid', 'wppgps-author-shield--error');
        authorShield.classList.add('wppgps-author-shield--pending');
        if (authorFpWrapper) authorFpWrapper.hidden = true;
    }
    function setAuthorBadgeValid(authorShield, authorFpWrapper, authorFpText, authorFpValue, fingerprint) {
        if (!authorShield) return;
        authorShield.classList.remove('wppgps-author-shield--pending', 'wppgps-author-shield--invalid', 'wppgps-author-shield--error');
        authorShield.classList.add('wppgps-author-shield--valid');
        if (authorFpValue) authorFpValue.textContent = fingerprint;
        if (authorFpText)  authorFpText.textContent  = 'Signed - Fingerprint: ';
        if (authorFpWrapper) authorFpWrapper.hidden = false;
    }
    function setAuthorBadgeInvalid(authorShield, authorFpWrapper, authorFpText, authorFpValue, fingerprint) {
        if (!authorShield) return;
        authorShield.classList.remove('wppgps-author-shield--pending', 'wppgps-author-shield--valid', 'wppgps-author-shield--error');
        authorShield.classList.add('wppgps-author-shield--invalid');
        if (authorFpValue) authorFpValue.textContent = fingerprint;
        if (authorFpText)  authorFpText.textContent  = 'Invalid signature - Fingerprint: ';
        if (authorFpWrapper) authorFpWrapper.hidden = false;
    }
    function setAuthorBadgeError(authorShield, authorFpWrapper, authorFpText, authorFpValue, message) {
        if (!authorShield) return;
        authorShield.classList.remove('wppgps-author-shield--pending', 'wppgps-author-shield--valid', 'wppgps-author-shield--invalid');
        authorShield.classList.add('wppgps-author-shield--error');
        if (authorFpValue) authorFpValue.textContent = (message || '').toString();
        if (authorFpText)  authorFpText.textContent  = 'Verification error: ';
        if (authorFpWrapper) authorFpWrapper.hidden = false;
    }

    function init() {
        // --- Replace author-badge token ASAP (covers themes that escape meta HTML) ---
        var postId = (typeof wppgpsData !== 'undefined' && wppgpsData.postId) ? wppgpsData.postId : 0;
        var token  = (typeof wppgpsData !== 'undefined' && wppgpsData.badgeToken) ? wppgpsData.badgeToken : ('[[WPPGPS_BADGE:' + postId + ']]');
        wppgpsReplaceBadgeTokens(postId, token);

        // Ensure the badge is not inside author links.
        detachBadgesFromLinks();

        // If no verification data, we still keep the badge token replacement above.
        if (typeof wppgpsData === 'undefined' || !wppgpsData.signature || !wppgpsData.publicKeyUrl) {
            return;
        }

        // --- DOM elements for the bottom status card ---
        var resultDiv   = document.getElementById('pgp-verification-result');
        var detailsSpan = document.getElementById('pgp-result-details');
        var keyUrlLink  = document.getElementById('pgp-key-url-link'); // span (not a link)

        // Data from PHP
        var message      = wppgpsData.message || '';
        var signature    = wppgpsData.signature || '';
        var publicKeyUrl = wppgpsData.publicKeyUrl || '';

        // Update the key "Source" text (plain text, NOT a link)
        if (keyUrlLink) {
            keyUrlLink.textContent = publicKeyUrl;
        }

        // Find the author badge elements
        var authorBadge     = document.querySelector('.wppgps-author-badge[data-post-id="' + postId + '"]');
        var authorShield    = authorBadge ? authorBadge.querySelector('.wppgps-author-shield') : null;
        var authorFpWrapper = authorBadge ? authorBadge.querySelector('.wppgps-author-fp') : null;
        var authorFpValue   = authorBadge ? authorBadge.querySelector('.wppgps-author-fp-value') : null;
        var authorFpText    = authorBadge ? authorBadge.querySelector('.wppgps-author-fp-text') : null;

        // Verify library availability
        if (typeof openpgp === 'undefined') {
            updateStatus(resultDiv, 'pgp-status-error', '❌ The OpenPGP library did not load correctly.');
            setAuthorBadgeError(authorShield, authorFpWrapper, authorFpText, authorFpValue, 'Library not loaded');
            detachBadgesFromLinks();
            return;
        }

        // Start pending state for badge
        setAuthorBadgePending(authorShield, authorFpWrapper);

        // Main async verification
        (async function verifySignature() {
            try {
                updateStatus(resultDiv, 'pgp-status-pending', '🌐 Downloading public key...');

                var response = await fetch(publicKeyUrl);
                if (!response || !response.ok) {
                    throw new Error('HTTP Error ' + (response ? response.status : '0') + ' while downloading the key.');
                }
                var publicKeyArmored = await response.text();

                // OpenPGP.js 6.x API
                var publicKey    = await openpgp.readKey({ armoredKey: publicKeyArmored });
                var signedMessage = await openpgp.createMessage({ text: message });
                var signatureRead = await openpgp.readSignature({ armoredSignature: signature });

                updateStatus(resultDiv, 'pgp-status-pending', '🔍 Verifying signature...');

                var verificationResult = await openpgp.verify({
                    message: signedMessage,
                    signature: signatureRead,
                    verificationKeys: [ publicKey ]
                });

                var signaturesArray = verificationResult && verificationResult.signatures ? verificationResult.signatures : [];
                if (!signaturesArray.length) {
                    throw new Error('No valid signatures found in the verification result.');
                }

                // In 6.x: .verified is a Promise and the property is keyID
                var isValid      = await signaturesArray[0].verified;
                var signerKeyID  = signaturesArray[0].keyID.toHex();
                var formattedKey = formatKeyId(signerKeyID);

                if (isValid) {
                    updateStatus(resultDiv, 'pgp-status-correct', '✅ VERIFICATION CORRECT');
                    if (detailsSpan) detailsSpan.textContent = 'Valid signature. Matches the fingerprint: ' + formattedKey;
                    setAuthorBadgeValid(authorShield, authorFpWrapper, authorFpText, authorFpValue, formattedKey);
                } else {
                    updateStatus(resultDiv, 'pgp-status-incorrect', '❌ VERIFICATION INCORRECT');
                    if (detailsSpan) detailsSpan.textContent = 'Invalid signature. Does not match the fingerprint: ' + formattedKey;
                    setAuthorBadgeInvalid(authorShield, authorFpWrapper, authorFpText, authorFpValue, formattedKey);
                }

                // After potential layout shifts, keep badge outside the link.
                detachBadgesFromLinks();

            } catch (error) {
                updateStatus(resultDiv, 'pgp-status-error', '⚠️ VERIFICATION ERROR');
                if (detailsSpan) detailsSpan.textContent = 'Error processing the key or signature. (' + (error && error.message ? error.message : 'Unknown error') + ')';
                setAuthorBadgeError(authorShield, authorFpWrapper, authorFpText, authorFpValue, error && error.message ? error.message : 'Unknown error');
                detachBadgesFromLinks();
            }
        })();
    }

    // Run on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
