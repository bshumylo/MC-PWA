/**
 * MC PWA – push notification settings of the current user (stored in Preferences).
 */
define(['views/modal'], (Dep) => {

    const TYPES = ['Assign', 'EmailReceived', 'Message', 'Note', 'MentionInPost', 'System', 'Reminder'];

    return class extends Dep {

        templateContent = `
            <div class="margin-bottom"><strong>{{translate 'Notification types' category='labels'}}</strong></div>
            {{#each typeList}}
                <div class="checkbox"><label>
                    <input type="checkbox" data-role="type" data-name="{{name}}" {{#if checked}}checked{{/if}}>
                    {{label}}
                </label></div>
            {{/each}}
            <hr>
            <div class="checkbox"><label>
                <input type="checkbox" data-role="quiet-enabled" {{#if quietEnabled}}checked{{/if}}>
                <strong>{{translate 'Do not disturb' category='labels'}}</strong>
            </label></div>
            <div class="form-inline margin-bottom">
                <input type="time" class="form-control" data-role="quiet-start" value="{{quietStart}}">
                &ndash;
                <input type="time" class="form-control" data-role="quiet-end" value="{{quietEnd}}">
            </div>
            <div class="text-muted small">{{translate 'quietHoursHint' category='labels'}}</div>
        `

        setup() {
            this.headerText = this.translate('Push Notification Settings', 'labels');

            this.buttonList = [
                {name: 'save', label: 'Save', style: 'primary'},
                {name: 'cancel', label: 'Cancel'},
            ];

            this.preferences = {};

            this.wait(
                Espo.Ajax.getRequest('Preferences/' + this.getUser().id)
                    .then(data => {
                        this.preferences = data || {};
                    })
            );
        }

        data() {
            const selected = Array.isArray(this.preferences.pwaPushTypes) ? this.preferences.pwaPushTypes : null;

            return {
                typeList: TYPES.map(name => ({
                    name: name,
                    label: this.getLanguage().translateOption(name, 'pwaPushTypes', 'Preferences'),
                    checked: selected === null || selected.includes(name),
                })),
                quietEnabled: !!this.preferences.pwaQuietHoursEnabled,
                quietStart: this.preferences.pwaQuietHoursStart || '22:00',
                quietEnd: this.preferences.pwaQuietHoursEnd || '07:00',
            };
        }

        actionSave() {
            const el = this.element;

            const types = [...el.querySelectorAll('[data-role="type"]:checked')].map(it => it.dataset.name);

            const value = {
                // Everything selected means "the default": types added later are delivered too.
                pwaPushTypes: types.length === TYPES.length ? null : types,
                pwaQuietHoursEnabled: el.querySelector('[data-role="quiet-enabled"]').checked,
                pwaQuietHoursStart: el.querySelector('[data-role="quiet-start"]').value || '22:00',
                pwaQuietHoursEnd: el.querySelector('[data-role="quiet-end"]').value || '07:00',
            };

            this.disableButton('save');

            Espo.Ajax.putRequest('Preferences/' + this.getUser().id, value)
                .then(() => {
                    Espo.Ui.success(this.translate('pushSettingsSaved', 'labels'));

                    this.close();
                })
                .catch(() => this.enableButton('save'));
        }
    };
});
