define(['views/settings/record/edit'], (Dep) => {

    return class extends Dep {

        detailLayout = [
            {
                label: 'General',
                rows: [
                    [
                        {name: 'pwaEnabled'},
                        {name: 'pwaIcon'},
                    ],
                    [
                        {name: 'pwaAppName'},
                        {name: 'pwaShortName'},
                    ],
                    [
                        {name: 'pwaThemeColor'},
                        {name: 'pwaBackgroundColor'},
                    ],
                ],
            },
            {
                label: 'Push Notifications',
                rows: [
                    [
                        {name: 'pwaPushEnabled'},
                        {name: 'pwaPushNotificationTypes'},
                    ],
                    [
                        {name: 'pwaVapidPublicKey'},
                        false,
                    ],
                ],
            },
            {
                label: 'Bottom Navigation Bar',
                rows: [
                    [
                        {name: 'pwaBottomBarEnabled'},
                        {name: 'pwaBottomBarShowLabels'},
                    ],
                    [
                        {name: 'pwaBottomBarItems'},
                        false,
                    ],
                ],
            },
            {
                label: 'Installation Statistics',
                rows: [
                    [
                        {name: 'pwaStatsEnabled'},
                        {name: 'pwaInstallationRetentionDays'},
                    ],
                ],
            },
            {
                label: 'Push Subscriptions',
                rows: [
                    [
                        {name: 'pwaSubscriptionsEnabled'},
                        {name: 'pwaSubscriptionMaxFailures'},
                    ],
                ],
            },
        ];

        setup() {
            super.setup();

            this.addButton({
                name: 'testPush',
                label: this.translate('Send Test Push', 'labels'),
                style: 'default',
            });

            this.addButton({
                name: 'diagnostics',
                label: this.translate('Diagnostics', 'labels'),
                style: 'default',
            });

            this.addButton({
                name: 'rotateVapid',
                label: this.translate('Rotate VAPID Keys', 'labels'),
                style: 'danger',
            });
        }

        actionDiagnostics() {
            this.disableButtons();

            Espo.Ajax.getRequest('McPwa/status')
                .then(data => {
                    this.enableButtons();

                    return this.createView('diagnostics', 'mc-pwa:views/modals/diagnostics', {data: data});
                })
                .then(view => view.render())
                .catch(() => this.enableButtons());
        }

        actionRotateVapid() {
            this.confirm(this.translate('rotateVapidConfirm', 'labels'), () => {
                this.disableButtons();

                Espo.Ajax.postRequest('McPwa/rotateVapid', {confirm: true})
                    .then(response => {
                        this.enableButtons();

                        this.model.set('pwaVapidPublicKey', response.vapidPublicKey);

                        Espo.Ui.success(
                            this.translate('rotateVapidDone', 'labels')
                                .replace('{count}', response.removedSubscriptions)
                        );
                    })
                    .catch(() => this.enableButtons());
            });
        }

        actionTestPush() {
            this.disableButtons();

            const base = window.location.pathname.replace(/[^/]*$/, '');

            fetch(base + 'api/v1/McPwa/testPush', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: '{}',
            })
                .then(r => {
                    if (!r.ok) {
                        throw new Error('HTTP ' + r.status);
                    }

                    return r.json();
                })
                .then(response => {
                    this.enableButtons();

                    const total = response.total || 0;

                    if (!total) {
                        Espo.Ui.warning(
                            this.translate('testPushNoSubscriptions', 'labels')
                        );

                        return;
                    }

                    const sent = (response.list || [])
                        .filter(item => item.status === 'sent')
                        .length;

                    const message = this.translate('testPushSent', 'labels')
                        .replace('{sent}', sent)
                        .replace('{total}', total);

                    if (sent > 0) {
                        Espo.Ui.success(message);
                    } else {
                        Espo.Ui.error(message);
                    }

                    console.log('MC PWA test push results:', response.list);
                })
                .catch(() => this.enableButtons());
        }
    };
});
