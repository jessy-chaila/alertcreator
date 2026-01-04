/**
 * Plugin AlertCreator - File: js/alertcreator.js
 */

document.addEventListener('DOMContentLoaded', function () {
  // Only execute on ticket forms
  if (!window.location.pathname.includes('ticket.form.php')) {
    return;
  }

  // Display message after page reload if stored in session
  const lastMsg = sessionStorage.getItem('alertcreator_last_message');
  if (lastMsg) {
    alert(lastMsg);
    sessionStorage.removeItem('alertcreator_last_message');
  }

  /**
   * Creates the alert modal if it doesn't already exist
   * @param {number} ticketId 
   */
  function createModalIfNeeded(ticketId) {
    let modal = document.getElementById('alertcreator-modal');
    if (modal) {
      const ticketInput = modal.querySelector('input[name="ticket_id"]');
      if (ticketInput) ticketInput.value = ticketId;
      return modal;
    }

    // Modal HTML structure with strings ready for internationalization
    const modalHtml = `
<div class="modal fade" id="alertcreator-modal" tabindex="-1" aria-labelledby="alertcreator-modal-label" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="alertcreator-modal-label">Créer une alerte</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <form id="alertcreator-form">
          <input type="hidden" name="ticket_id" value="${ticketId}">
          <div class="mb-3">
            <label for="alertcreator-email" class="form-label">Adresse e-mail cible</label>
            <input type="email" class="form-control" id="alertcreator-email" name="target_email" required>
          </div>
          <div class="mb-3">
            <label for="alertcreator-date" class="form-label">Date de l'action à effectuer</label>
            <input type="datetime-local" class="form-control" id="alertcreator-date" name="reminder_date" required>
          </div>
          <div class="mb-3">
            <label for="alertcreator-message" class="form-label">Message</label>
            <textarea class="form-control" id="alertcreator-message" name="message" rows="4" required></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-primary" id="alertcreator-submit">Créer l'alerte</button>
      </div>
    </div>
  </div>
</div>`;

    document.body.insertAdjacentHTML('beforeend', modalHtml);
    modal = document.getElementById('alertcreator-modal');

    const submitBtn = modal.querySelector('#alertcreator-submit');
    submitBtn.addEventListener('click', function () {
      const form = document.getElementById('alertcreator-form');
      const formData = new FormData(form);

      const payload = new FormData();
      payload.append('ticket_id', formData.get('ticket_id'));
      payload.append('target_email', formData.get('target_email'));
      payload.append('reminder_date', formData.get('reminder_date'));
      payload.append('message', formData.get('message'));

      const csrfInput = document.querySelector('input[name="_glpi_csrf_token"]');
      if (csrfInput && csrfInput.value) {
        payload.append('_glpi_csrf_token', csrfInput.value);
      }

      const root = window.CFG_GLPI ? CFG_GLPI.root_doc : '';
      const url = root + '/plugins/alertcreator/front/alert.ajax.php';

      // Send alert data via AJAX
      fetch(url, {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
      })
        .then(async (response) => {
          const text = await response.text();
          let json = {};
          try {
            json = JSON.parse(text);
          } catch (e) {}
          if (!response.ok || !json.success) {
            const msg = (json && json.message) || 'Erreur inconnue lors de la création de l’alerte';
            throw new Error(msg);
          }
          return json;
        })
        .then((json) => {
          const msg = json.message || 'Alerte envoyée avec succès.';
          sessionStorage.setItem('alertcreator_last_message', msg);
          const instance = bootstrap.Modal.getInstance(modal);
          if (instance) instance.hide();
          window.location.reload();
        })
        .catch((err) => {
          alert('Erreur lors de la création de l’alerte : ' + err.message);
        });
    });

    return modal;
  }

  /**
   * Injects the alert creation button into the ticket's main actions menu
   */
  function injectAlertButton() {
    const dropdownMenu = document.querySelector('.main-actions .dropdown-menu');
    if (!dropdownMenu) return false;

    if (dropdownMenu.querySelector('.action-alertcreator')) return true;

    const idInput = document.querySelector('#itil-form input[name="id"]');
    const ticketId = idInput ? idInput.value : null;
    if (!ticketId) return false;

    const alertItem = document.createElement('li');
    const alertLink = document.createElement('a');
    alertLink.className = 'dropdown-item action-alertcreator bg-danger-subtle text-dark';
    alertLink.href = '#';
    alertLink.innerHTML = `
      <i class="ti ti-refresh-alert"></i>
      <span>Créer une alerte</span>
    `;

    // Hover effect: font-weight bold (matching GLPI standards)
    alertLink.addEventListener('mouseenter', () => {
      alertLink.style.fontWeight = '600';
    });
    alertLink.addEventListener('mouseleave', () => {
      alertLink.style.fontWeight = '';
    });

    alertLink.addEventListener('click', function (e) {
      e.preventDefault();
      const modal = createModalIfNeeded(ticketId);
      new bootstrap.Modal(modal).show();
    });

    alertItem.appendChild(alertLink);
    dropdownMenu.appendChild(alertItem);

    return true;
  }

  // Fast injection attempt
  if (!injectAlertButton()) {
    let attempts = 0;
    const interval = setInterval(() => {
      attempts++;
      // Stop trying after 15 attempts (~3 seconds)
      if (injectAlertButton() || attempts > 15) {
        clearInterval(interval);
      }
    }, 200);
  }
});
