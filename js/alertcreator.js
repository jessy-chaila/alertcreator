/**
 * Plugin AlertCreator - File: js/alertcreator.js
 * Production Version
 */

document.addEventListener('DOMContentLoaded', function () {

    // 1. Security Check: Only run on ticket forms
    if (!window.location.pathname.includes('ticket.form.php')) {
        return;
    }

    // 2. Handle Success Message (from previous page reload)
    const lastMsg = sessionStorage.getItem('alertcreator_last_message');
    if (lastMsg) {
        // You can replace alert() with a nicer GLPI toast if preferred later
        alert(lastMsg);
        sessionStorage.removeItem('alertcreator_last_message');
    }

    /**
     * Creates the modal dialog if it doesn't exist
     * @param {number} ticketId 
     * @returns {HTMLElement} The modal element
     */
    function createModalIfNeeded(ticketId) {
        let modal = document.getElementById('alertcreator-modal');
        
        // If exists, just update the hidden ticket_id
        if (modal) {
            const ticketInput = modal.querySelector('input[name="ticket_id"]');
            if (ticketInput) ticketInput.value = ticketId;
            return modal;
        }

        // Inject Modal HTML (Bootstrap 5 compatible)
        const modalHtml = `
        <div class="modal fade" id="alertcreator-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Créer une alerte</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <form id="alertcreator-form">
                            <input type="hidden" name="ticket_id" value="${ticketId}">
                            <div class="mb-3">
                                <label class="form-label">Adresse e-mail cible</label>
                                <input type="email" class="form-control" name="target_email" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Date de l'action</label>
                                <input type="datetime-local" class="form-control" name="reminder_date" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Message</label>
                                <textarea class="form-control" name="message" rows="4" required></textarea>
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

        // Attach Submit Event
        const submitBtn = modal.querySelector('#alertcreator-submit');
        submitBtn.addEventListener('click', function () {
            handleFormSubmit(modal);
        });

        return modal;
    }

    /**
     * Handles the AJAX submission
     * @param {HTMLElement} modal 
     */
    function handleFormSubmit(modal) {
        const form = document.getElementById('alertcreator-form');
        const formData = new FormData(form);

        // Build Payload
        const payload = new FormData();
        payload.append('ticket_id', formData.get('ticket_id'));
        payload.append('target_email', formData.get('target_email'));
        payload.append('reminder_date', formData.get('reminder_date'));
        payload.append('message', formData.get('message'));

        // Add CSRF Token
        const csrfInput = document.querySelector('input[name="_glpi_csrf_token"]');
        if (csrfInput && csrfInput.value) {
            payload.append('_glpi_csrf_token', csrfInput.value);
        }

        // Define URL
        const root = window.CFG_GLPI ? CFG_GLPI.root_doc : '';
        const url = root + '/plugins/alertcreator/front/alert.ajax.php';

        // Send Request
        fetch(url, {
            method: 'POST',
            body: payload,
            credentials: 'same-origin',
        })
        .then(async (response) => {
            const text = await response.text();
            let json = {};
            try { json = JSON.parse(text); } catch (e) {}
            
            if (!response.ok || !json.success) {
                throw new Error((json && json.message) || 'Erreur inconnue');
            }
            return json;
        })
        .then((json) => {
            // Success
            const msg = json.message || 'Alerte envoyée avec succès.';
            sessionStorage.setItem('alertcreator_last_message', msg);
            
            // Close modal and reload
            const instance = bootstrap.Modal.getInstance(modal);
            if (instance) instance.hide();
            window.location.reload();
        })
        .catch((err) => {
            alert('Erreur : ' + err.message);
        });
    }

    /**
     * Injects the button into the Action Menu
     * Compatible with GLPI 10 and GLPI 11+
     */
    function injectAlertButton() {
        // 1. Check for duplicates
        if (document.querySelector('.action-alertcreator')) return true;

        // 2. Find the dropdown menu (Standard or Timeline view)
        let dropdownMenu = document.querySelector('.main-actions .dropdown-menu') ||
                           document.querySelector('.timeline-actions .dropdown-menu');

        // Failsafe: if not found by container, look for a sibling button
        if (!dropdownMenu) {
            const sibling = document.querySelector('.action-task, .action-solution');
            if (sibling) dropdownMenu = sibling.closest('.dropdown-menu');
        }

        if (!dropdownMenu) return false;

        // 3. Get Ticket ID
        const idInput = document.querySelector('#itil-form input[name="id"]');
        if (!idInput) return false;

        // 4. Create Button Elements
        const li = document.createElement('li');
        const link = document.createElement('a');
        
        // Styles: "bg-danger-subtle" for light red background (Alert context)
        link.className = 'dropdown-item action-alertcreator bg-danger-subtle text-dark';
        link.href = '#';
        link.innerHTML = `
            <i class="ti ti-bell"></i>
            <span>Créer une alerte</span>
        `;

        // Hover effects
        link.addEventListener('mouseenter', () => { link.style.fontWeight = '600'; });
        link.addEventListener('mouseleave', () => { link.style.fontWeight = ''; });

        // Click Event
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const modal = createModalIfNeeded(idInput.value);
            new bootstrap.Modal(modal).show();
        });

        li.appendChild(link);

        // 5. Insert Position: Try to place before "Purchase Manager" or append
        const purchaseBtn = dropdownMenu.querySelector('.action-purchasemanager');
        if (purchaseBtn && purchaseBtn.parentElement) {
            dropdownMenu.insertBefore(li, purchaseBtn.parentElement);
        } else {
            dropdownMenu.appendChild(li);
        }

        return true;
    }

    // Attempt injection with retry loop (for slow loading DOMs)
    if (!injectAlertButton()) {
        let attempts = 0;
        const interval = setInterval(() => {
            attempts++;
            if (injectAlertButton() || attempts > 20) {
                clearInterval(interval);
            }
        }, 200);
    }
});
