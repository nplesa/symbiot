import './bootstrap';

import Swal from 'sweetalert2';

window.Swal = Swal;

import './swal-confirm';

import './trasee/google-maps-import';
import './trasee/file-import';

// Sidebar and theme are needed on every page; GPS tracking and the map-first
// dashboard (pages/dashboard.js) are loaded only by the home page.
import './shell.js';