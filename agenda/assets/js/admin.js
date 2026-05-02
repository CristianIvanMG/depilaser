/* BellaNick Agenda — JS admin */
(function () {
  'use strict';

  // Sidebar toggle mobile
  var sb = document.getElementById('bncSidebar');
  var open = document.getElementById('bncSidebarOpen');
  var close = document.getElementById('bncSidebarClose');
  if (open && sb) open.addEventListener('click', function () { sb.classList.add('open'); });
  if (close && sb) close.addEventListener('click', function () { sb.classList.remove('open'); });

  // Auto-cerrar alerts
  document.querySelectorAll('.alert.fade.show').forEach(function (a) {
    setTimeout(function () { try { bootstrap.Alert.getOrCreateInstance(a).close(); } catch (e) {} }, 6000);
  });
})();
