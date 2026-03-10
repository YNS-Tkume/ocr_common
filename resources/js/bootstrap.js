import axios from 'axios';
import { taxRateModal } from './tax-rates/tax-rate-modal';
import { dropdownComponent } from './components/dropdown';
import { orderItemModal } from './order-items/order-item-modal';
import { propertyModal } from './properties/property-modal';
import { pdfSelectionModal } from './pages/client/orders/index';
import { editingWorkChatModal } from './pages/common/chat/editing-work-chat';
import { shootingProjectChatModal } from './pages/common/chat/shooting-project-chat';
import { shootingProjectDetail } from './shooting-projects/detail';
import { troubleReportModal, todayTomorrowShootingProjectsPage } from './shooting-projects/today-tomorrow-trouble-modal';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.headers.common['Accept'] = 'application/json';

const csrfTokenMeta = document.head.querySelector('meta[name="csrf-token"]');
if (csrfTokenMeta) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfTokenMeta.content;
    window.csrfToken = csrfTokenMeta.content;
}

// Make components available globally for Alpine.js before it starts
window.taxRateModal = taxRateModal;
window.dropdownComponent = dropdownComponent;
window.orderItemModal = orderItemModal;
window.propertyModal = propertyModal;
window.pdfSelectionModal = pdfSelectionModal;
window.editingWorkChatModal = editingWorkChatModal;
window.shootingProjectChatModal = shootingProjectChatModal;
window.shootingProjectDetail = shootingProjectDetail;
window.troubleReportModal = troubleReportModal;
window.todayTomorrowShootingProjectsPage = todayTomorrowShootingProjectsPage;

