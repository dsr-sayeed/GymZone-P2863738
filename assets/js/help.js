/* Help page: send a message to the housing office. */
(function () {
  'use strict';
  // The contact form on the Help page.
  const form = document.getElementById('contact-form');
  if (!form) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    try {
      // Send the topic and message to api/contact.php, then clear the form.
      const res = await GZ.busy(form.querySelector('button[type=submit]'), () => GZ.api('api/contact.php', GZ.formData(form)));
      form.reset();
      GZ.toast(res.message);
      setTimeout(() => window.location.reload(), 1500);
    } catch (err) { GZ.toast(err.message, 'error'); }
  });
})();
