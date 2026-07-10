import EditView from 'views/settings/record/edit';

export default class PwaSettingsView extends EditView {

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
            label: 'Statistics',
            rows: [
                [
                    {name: 'pwaStatsEnabled'},
                    false,
                ],
            ],
        },
    ];
}
