import Alert from 'bootstrap/js/dist/alert';
import Modal from 'bootstrap/js/dist/modal';
import Toast from 'bootstrap/js/dist/toast';
import Tooltip from 'bootstrap/js/dist/tooltip';
import { installClinicBootstrap } from './clinic-bootstrap-ui.js';

installClinicBootstrap({ window, document, Alert, Modal, Toast, Tooltip });
