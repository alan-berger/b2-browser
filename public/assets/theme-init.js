/*
 * Runs synchronously in <head>, before the page paints, so there is no
 * flash of the wrong theme. The storage key comes from data-theme-key on
 * <html>; using the same key as the surrounding site shares the visitor's
 * light/dark choice between the two.
 */
(function () {
    'use strict';
    var root = document.documentElement;
    var key = root.getAttribute('data-theme-key') || 'b2-theme';
    var stored = null;
    try { stored = window.localStorage.getItem(key); } catch (e) { /* storage blocked */ }
    var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    root.setAttribute('data-theme', stored === 'dark' || stored === 'light' ? stored : (prefersDark ? 'dark' : 'light'));
}());
