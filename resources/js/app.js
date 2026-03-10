import './bootstrap';
import { initSubmitGuards } from './common/submit-guard';
import Alpine from 'alpinejs';

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initSubmitGuards());
} else {
    initSubmitGuards();
}

// Initialize Alpine globally
window.Alpine = Alpine;

// Import Alpine components BEFORE starting Alpine
import './pdf-editor';
import './invoices/operations';
import './orders/modals';
import './common/chat/modal-bridge';

// Start Alpine
Alpine.start();
