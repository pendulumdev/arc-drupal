(function (Drupal, once, drupalSettings) {
  'use strict';

  function paint(count) {
    const link = document.querySelector('.toolbar-icon-pendulum-arc-dashboard');
    if (!link) {
      return;
    }
    let badge = document.getElementById('pendulum-arc-menu-count');
    const total = Number(count) || 0;
    if (total < 1) {
      if (badge) {
        badge.hidden = true;
      }
      return;
    }
    if (!badge) {
      badge = document.createElement('span');
      badge.id = 'pendulum-arc-menu-count';
      badge.className = 'pendulum-arc-menu-count';
      link.appendChild(badge);
    }
    badge.hidden = false;
    badge.textContent = String(total);
  }

  window.pendulumArcSetMenuCount = paint;

  Drupal.behaviors.pendulumArcMenuCount = {
    attach(context) {
      const settings = drupalSettings.pendulumArc || {};
      once('pendulum-arc-menu-count', '.toolbar-icon-pendulum-arc-dashboard', context).forEach(function () {
        paint(settings.menuCount);
      });
    },
  };
})(Drupal, once, drupalSettings);
