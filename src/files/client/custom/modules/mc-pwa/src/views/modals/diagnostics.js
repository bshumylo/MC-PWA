/**
 * MC PWA – push diagnostics for administrators.
 */
define(['views/modal'], (Dep) => {

    return class extends Dep {

        templateContent = '<div class="mc-pwa-diagnostics">{{{html}}}</div>'

        setup() {
            this.headerText = this.translate('Diagnostics', 'labels');

            this.buttonList = [
                {name: 'cancel', label: 'Close'},
            ];

            this.report = this.options.data || {};
        }

        /** @private */
        escape(value) {
            return this.getHelper().escapeString(value === null || value === undefined ? '' : String(value));
        }

        /** @private */
        row(label, value) {
            return '<tr><td class="text-soft" style="width: 45%">' + this.escape(label) + '</td><td>' +
                value + '</td></tr>';
        }

        data() {
            const t = name => this.translate(name, 'labels');
            const data = this.report;
            const env = data.environment || {};

            const yesNo = value => value ? '✔' : '✖';

            let html = '<table class="table table-condensed"><tbody>';

            html += this.row(t('diagVapid'), this.escape(data.vapidConfigured ? t('diagConfigured') : t('diagMissing')));
            html += this.row(t('diagSubscriptions'), this.escape(
                (data.subscriptions || {}).total + ' (' + (data.subscriptions || {}).failing + ' ' + t('diagFailing') + ')'
            ));
            html += this.row(t('diagInstallations'), this.escape(data.installations));
            html += this.row(t('diagReminderChecked'), this.escape(data.reminderCheckedAt || t('diagNever')));
            html += this.row(
                t('diagEnvironment'),
                this.escape('PHP ' + env.php + ' · openssl ' + yesNo(env.openssl) + ' · curl ' +
                    yesNo(env.curl) + ' · gd ' + yesNo(env.gd))
            );

            html += '</tbody></table><h5>' + this.escape(t('diagRecentErrors')) + '</h5>';

            const errors = data.recentErrors || [];

            if (!errors.length) {
                html += '<p class="text-muted">' + this.escape(t('diagNoErrors')) + '</p>';
            } else {
                html += '<table class="table table-condensed"><tbody>';

                errors.forEach(item => {
                    html += '<tr><td>' + this.escape(item.at) + '</td><td>' +
                        this.escape((item.user || '') + ' / ' + (item.platform || '')) + '</td><td>' +
                        this.escape(item.error) + ' (×' + this.escape(item.failureCount) + ')</td></tr>';
                });

                html += '</tbody></table>';
            }

            return {html: html};
        }
    };
});
