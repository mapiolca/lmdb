/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
/* global jQuery */
(function () {
	'use strict';
	var payload = document.getElementById('lmdb-mailing-list-dates');
	if (!payload) return;
	var data = JSON.parse(payload.textContent);
	// The footer hook runs before the native table closes.
	jQuery(function () {
		var table = payload.closest('table');
		if (!table || table.dataset.lmdbScheduledDates) return;
		var header = table.querySelector('tr.liste_titre');
		if (!header) return;
		table.dataset.lmdbScheduledDates = '1';
		function insertCell(row, text, className) {
			var cell = row.insertCell(data.index);
			cell.className = className;
			cell.textContent = text;
		}
		insertCell(header, data.label, 'liste_titre center nowrap');
		var filter = table.querySelector('tr.liste_titre_filter');
		if (filter) insertCell(filter, '', 'liste_titre');
		table.querySelectorAll('tr[data-rowid]').forEach(function (row) {
			insertCell(row, data.dates[row.dataset.rowid] || '', 'center nowrap');
		});
		// v20-v24 use different empty-state colspan calculations.
		if (!table.querySelector('tr[data-rowid]')) {
			table.querySelectorAll('td[colspan]').forEach(function (cell) {
				if (cell.querySelector('.opacitymedium')) cell.colSpan = header.cells.length;
			});
		}
	});
})();
