import { ThrowResponseIfNotOk, fetch } from '../utils/http';
import { Controller } from '@hotwired/stimulus';

/**
 * Offers push notifications to a user who does not have them yet.
 *
 * The server cannot decide whether to show this: a push subscription belongs to one
 * browser on one device, and whether notifications are already blocked is known only
 * to the browser. So the element is rendered hidden and this controller reveals it,
 * in one of two shapes.
 *
 * On iOS, Safari can neither subscribe nor be asked to. Push exists there only for a
 * site installed to the home screen, so a tab gets the install instruction and no
 * button, because a button could not work. Everywhere else, and inside the installed
 * app on iOS, there is a single Enable button.
 */
export default class extends Controller {
    static targets = ['enableText', 'installText', 'enableButton'];
    static values = { applicationServerKey: String };

    async connect() {
        if (!this.applicationServerKeyValue) {
            // The instance has no VAPID keypair, so no subscription is possible.
            return;
        }

        if (!('serviceWorker' in navigator)) {
            return;
        }

        // This check comes FIRST, before any capability check, because iOS Safari
        // outside an installed web app defines neither Notification nor PushManager.
        // Testing for those before this point returns early on the one platform the
        // install instruction exists to serve, which is exactly the bug this ordering
        // fixes.
        if (this.isIos() && !this.isStandalone()) {
            this.installTextTarget.hidden = false;
            this.element.hidden = false;

            return;
        }

        if (!('Notification' in window) || !('PushManager' in window)) {
            return;
        }

        if ('denied' === Notification.permission) {
            // Blocked at the browser or OS level. Nothing this page does can undo that.
            return;
        }

        const registration = await navigator.serviceWorker.getRegistration();
        const subscription = await registration?.pushManager.getSubscription();

        if (subscription) {
            return;
        }

        this.enableTextTarget.hidden = false;
        this.enableButtonTarget.hidden = false;
        this.element.hidden = false;
    }

    async enable() {
        const permission = await Notification.requestPermission();

        if ('granted' !== permission) {
            return;
        }

        const registration = await navigator.serviceWorker.getRegistration();
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: this.applicationServerKeyValue,
        });

        const json = subscription.toJSON();

        await fetch('/ajax/register_push', {
            method: 'post',
            body: JSON.stringify({
                endpoint: subscription.endpoint,
                deviceKey: this.deviceKey(),
                contentPublicKey: json.keys['p256dh'],
                serverKey: json.keys['auth'],
            }),
            headers: { 'Content-Type': 'application/json' },
        }).then(ThrowResponseIfNotOk);

        this.element.hidden = true;
    }

    async decline() {
        this.element.hidden = true;

        await fetch('/ajax/decline_push_prompt', { method: 'post' })
            .then(ThrowResponseIfNotOk)
            .catch((error) => console.error(error));
    }

    isIos() {
        return /iP(hone|ad|od)/.test(navigator.platform)
            || ('MacIntel' === navigator.platform && 1 < navigator.maxTouchPoints);
    }

    isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches || true === window.navigator.standalone;
    }

    /**
     * The same key the notifications page uses, so enabling from either place produces
     * one subscription for this device rather than two.
     */
    deviceKey() {
        const storageKey = 'push_subscription_device_key';
        const existing = localStorage.getItem(storageKey);

        if (existing) {
            return existing;
        }

        const deviceKey = crypto.randomUUID();
        localStorage.setItem(storageKey, deviceKey);

        return deviceKey;
    }
}
