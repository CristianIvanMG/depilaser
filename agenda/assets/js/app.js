/* BellaNick Agenda — JS cliente */
(function () {
  'use strict';

  // Confirmar enlaces destructivos
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm(el.dataset.confirm)) e.preventDefault();
    });
  });

  // Fade out auto de alerts después de 6s
  document.querySelectorAll('.alert.fade.show').forEach(function (a) {
    setTimeout(function () {
      try { bootstrap.Alert.getOrCreateInstance(a).close(); } catch (e) {}
    }, 6000);
  });
})();
