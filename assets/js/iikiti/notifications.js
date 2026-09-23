/**
 * @typedef {object} Notification
 * @property {string} id
 * @property {string} message
 * @property {'info'|'success'|'warning'|'error'} [type]
 * @property {number|null} [duration]
 * @property {{label:string,run:()=>void}} [action]
 *
 * @typedef {object} NotificationEvent
 * @property {'notify'} kind
 * @property {Notification} notification
 * @property {'dismiss'} [kind2]
 */

const listeners = new Set();
const dismissed = new Set();
let counter = 0;

export const settings = {
	autoDismiss: true,
	durationSeconds: 10,
	position: 'bottom-right',
};

export const notifications = {
	notify: (payload) => {
		const id = `iikiti-${Date.now()}-${counter++}`;
		const n = {
			id,
			message: payload.message,
			type: payload.type ?? 'info',
			duration: payload.duration ?? settings.durationSeconds,
			action: payload.action,
		};
		if (!settings.autoDismiss) n.duration = null;
		for (const cb of listeners) cb({ kind: 'notify', notification: n });
		if (n.duration != null && n.duration > 0) {
			setTimeout(() => {
				if (!dismissed.has(id)) notifications.dismiss(id);
			}, n.duration * 1000);
		}

		return id;
	},
	dismiss: (id) => {
		if (dismissed.has(id)) return;
		dismissed.add(id);
		for (const cb of listeners) cb({ kind: 'dismiss', id });
	},
	on: (cb) => {
		listeners.add(cb);
		return () => listeners.delete(cb);
	},
};
