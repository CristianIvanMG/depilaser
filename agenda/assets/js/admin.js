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

  function normalizePhone(value) {
    var digits = String(value || '').replace(/\D+/g, '');
    if (digits.length > 10 && digits.indexOf('52') === 0) return digits.slice(-10);
    return digits.slice(0, 10);
  }

  function setupPhoneInput(input) {
    if (!input || input.dataset.phoneMaskReady === '1') return;
    input.dataset.phoneMaskReady = '1';
    input.setAttribute('inputmode', 'numeric');
    input.setAttribute('pattern', '[0-9]{10}');
    input.setAttribute('maxlength', '10');
    input.setAttribute('autocomplete', input.getAttribute('autocomplete') || 'tel');
    input.value = normalizePhone(input.value);

    ['input', 'paste', 'change'].forEach(function (eventName) {
      input.addEventListener(eventName, function () {
        var cleaned = normalizePhone(input.value);
        if (input.value !== cleaned) input.value = cleaned;
      });
    });
  }

  function setupPhoneMasks(root) {
    (root || document).querySelectorAll(
      'input[type="tel"], input[name="phone"], input[name="client_phone"], input[name="whatsapp"], input[data-phone-mask]'
    ).forEach(setupPhoneInput);
  }

  function normalizeEmail(value) {
    return String(value || '').replace(/\s+/g, '').toLowerCase().slice(0, 190);
  }

  function isValidEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(value || ''));
  }

  function emailError(input) {
    var value = normalizeEmail(input.value);
    if (input.required && value === '') return 'Ingresa tu correo.';
    if (value !== '' && !isValidEmail(value)) return 'Ingresa un correo valido.';
    return '';
  }

  function setupEmailInput(input) {
    if (!input || input.dataset.emailMaskReady === '1') return;
    input.dataset.emailMaskReady = '1';
    input.setAttribute('type', 'email');
    input.setAttribute('inputmode', 'email');
    input.setAttribute('maxlength', input.getAttribute('maxlength') || '190');
    input.setAttribute('autocomplete', input.getAttribute('autocomplete') || 'email');
    input.setAttribute('pattern', '[^\\s@]+@[^\\s@]+\\.[^\\s@]{2,}');
    input.value = normalizeEmail(input.value);

    input.addEventListener('input', function () {
      var cleaned = normalizeEmail(input.value);
      if (input.value !== cleaned) input.value = cleaned;
      input.setCustomValidity('');
    });
    ['blur', 'change'].forEach(function (eventName) {
      input.addEventListener(eventName, function () {
        input.value = normalizeEmail(input.value);
        input.setCustomValidity(emailError(input));
      });
    });
  }

  function setupEmailMasks(root) {
    (root || document).querySelectorAll(
      'input[type="email"], input[name="email"], input[name="client_email"], input[data-email-mask]'
    ).forEach(setupEmailInput);
  }

  setupPhoneMasks(document);
  setupEmailMasks(document);
  document.addEventListener('shown.bs.modal', function (event) {
    setupPhoneMasks(event.target);
    setupEmailMasks(event.target);
  });
  document.addEventListener('submit', function (event) {
    setupPhoneMasks(event.target);
    setupEmailMasks(event.target);
    var firstInvalidEmail = null;
    event.target.querySelectorAll('input[type="email"], input[name="email"], input[name="client_email"], input[data-email-mask]').forEach(function (input) {
      input.value = normalizeEmail(input.value);
      input.setCustomValidity(emailError(input));
      if (!firstInvalidEmail && input.validationMessage) firstInvalidEmail = input;
    });
    if (firstInvalidEmail) {
      event.preventDefault();
      event.stopPropagation();
      firstInvalidEmail.focus();
      firstInvalidEmail.reportValidity();
    }
  }, true);
})();
