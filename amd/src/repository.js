// paygw_airwallex/amd/src/repository.js (Example Update)
import Ajax from 'core/ajax';

export const getHppConfig = (component, paymentArea, itemId) => {
    return Ajax.call([{
        methodname: 'paygw_airwallex_get_hpp_config', // This methodname needs to be defined in db/services.xml
        args: {
            component: component,
            paymentarea: paymentArea,
            itemid: itemId,
        },
    }])[0];
};

// If transaction_complete is still a separate webservice, it might look like this:
// export const markTransactionComplete = (component, paymentArea, itemId, paymentIntentId) => {
//     return Ajax.call([{
//         methodname: 'paygw_airwallex_transaction_complete', // This methodname needs to be defined in db/services.xml
//         args: {
//             component: component,
//             paymentarea: paymentArea,
//             itemid: itemId,
//             paymentintentid: paymentIntentId,
//         },
//     }])[0];
// };