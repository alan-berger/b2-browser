/*
 * b2-browser client script. Loaded from 'self' under a CSP with no inline
 * script at all; every page shares this file and each block only runs when
 * its elements are present.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    // ---------------------------------------------------- confirm sign-out
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    // ------------------------------------------------ listing filter/search
    var typeFilter = document.getElementById('fileTypeFilter');
    var search = document.getElementById('fileNameSearch');
    if (typeFilter && search) {
        var items = document.querySelectorAll('.file-item');
        var apply = function () {
            var type = typeFilter.value;
            var text = search.value.toLowerCase();
            items.forEach(function (item) {
                var typeOk = type === 'all' || item.getAttribute('data-type') === type;
                var textOk = text === '' || (item.getAttribute('data-name') || '').indexOf(text) !== -1;
                item.hidden = !(typeOk && textOk);
            });
        };
        typeFilter.addEventListener('change', apply);
        search.addEventListener('input', apply);
    }

    // ------------------------------------- thumbnails that fail to load
    document.querySelectorAll('img.thumb').forEach(function (img) {
        var fallback = function () {
            var icon = document.createElement('span');
            icon.textContent = '\uD83D\uDDBC\uFE0F'; // framed picture
            var link = img.parentNode;
            link.parentNode.replaceChild(icon, link);
        };
        if (img.complete && img.naturalWidth === 0) { fallback(); } else { img.addEventListener('error', fallback); }
    });

    // ---------------------------------------------------------- video player
    var video = document.getElementById('videoPlayer');
    if (video) {
        var unmuted = false;
        var unmute = function () {
            if (!unmuted) {
                unmuted = true;
                video.muted = false;
            }
        };
        video.addEventListener('click', unmute);
        video.addEventListener('touchstart', unmute, { passive: true });

        document.addEventListener('keydown', function (e) {
            if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) {
                return;
            }
            switch (e.key) {
                case ' ':
                case 'k':
                    e.preventDefault();
                    unmute();
                    if (video.paused) { video.play(); } else { video.pause(); }
                    break;
                case 'f':
                    if (video.requestFullscreen) { video.requestFullscreen(); }
                    break;
                case 'm':
                    video.muted = !video.muted;
                    unmuted = true;
                    break;
                case 'ArrowLeft':
                    video.currentTime = Math.max(0, video.currentTime - 5);
                    break;
                case 'ArrowRight':
                    video.currentTime += 5;
                    break;
            }
        });

        var showError = function () {
            var box = document.getElementById('videoError');
            if (box) { box.hidden = false; }
        };
        video.addEventListener('error', showError);
        var source = video.querySelector('source');
        if (source) { source.addEventListener('error', showError); }
    }

    // ------------------------------------------------------ MFA setup QR code
    var qrTarget = document.getElementById('qrcode');
    if (qrTarget) {
        try {
            if (typeof qrcode !== 'function') { throw new Error('QR library not loaded'); }
            var qr = qrcode(0, 'M');
            qr.addData(qrTarget.getAttribute('data-otpauth'));
            qr.make();
            // createSvgTag emits only presentational attributes (no inline
            // style), so it is permitted by the page's CSP.
            qrTarget.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 4, scalable: true, alt: 'TOTP enrolment QR code' });
        } catch (err) {
            var fallback = document.getElementById('qr-fallback');
            if (fallback) { fallback.hidden = false; }
            var container = document.getElementById('qrcode-container');
            if (container) { container.hidden = true; }
        }
    }
});
