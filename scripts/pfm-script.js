const DEBUG_LOG = true;
const PAYFORME_GATEWAY_ID = payForMeLocalizedData.payformeGatewayId;
const NONCE = payForMeLocalizedData.nonce;
const AJAX_URL = payForMeLocalizedData.ajaxurl;
const PAYFORME_AJAX_ACTION = "payforme_ajax_action";
const PAYFORME_REQUEST_CREATE_CHECKOUT = "payforme-request-create-checkout";
const PAYFORME_SHIPPING_ADDRESS_ACTION = "payforme-post-shipping-address";

// React Dialog Components
const COMPONENT_STORE_CHECKOUT = "store-checkout";
const COMPONENT_DISPLAY_PAYMENT_LINK = "display-payment-link";

jQuery(document).ready(function ($) {
  observeWindowEvents();
  observeChangesInWcCheckoutPage();

  $(document).click(function (e) {
    const targetId = e.target.id;
    consoleLog("clicked targetId: " + targetId);

    if (targetId === "payforme_button_in_checkout") {
      onClickPayForMeInCheckoutPage(e);
    }

    if (targetId === "payforme_button_in_cart") {
      onClickPayForMeInCartPage(e);
      return;
    }
  });

  /**
   * Fetch/create the payment link data and then display it in the Dialog ui.
   * The ui is where the shopper can copy and share the payment link.
   *
   * @param  {{action, address, shopper}} eventData payload containing the shopper info
   * and new shipping address that will be used to recalculate for new totals.
   * @returns {any} the payment link data from the server.
   */
  async function fetchAndDisplayPaymentLinkData(eventData) {
    consoleLog("fetchAndDisplayPaymentLinkData");

    const data = await createPaymentLinkData(eventData);
    if (data) {
      consoleLog("paymentLinkData=> " + JSON.stringify(data));
      showModalDialog(COMPONENT_DISPLAY_PAYMENT_LINK, data);
    }
  }

  async function createPaymentLinkData(eventData) {
    consoleLog("createPaymentLinkData");

    try {
      const res = await $.ajax({
        url: AJAX_URL,
        type: "POST",
        data: {
          action: PAYFORME_AJAX_ACTION,
          type: PAYFORME_REQUEST_CREATE_CHECKOUT,
          nonce: NONCE,
          msgData: eventData,
        },
      });

      const data = JSON.parse(res);
      return data;
    } catch (error) {
      const errorMsg = error.responseText ?? error.message ?? error.statusText;
      consoleLog("errorMsg: " + errorMsg);
      dispatchErrorToComponent(COMPONENT_STORE_CHECKOUT, errorMsg);
    }
  }

  /**
   * Gets the shipping address the user entered in the wc checkout page.
   * This is useful to avoid asking the user to re-enter the shipping address
   * on the pfm side if they already entered it in the store's checkout page
   * @returns ShippingAddress|null
   */
  function getShippingAddressEnteredOnWcCheckoutPage() {
    consoleLog("getShippingAddressEnteredOnWcCheckoutPage");

    if (!$("body").hasClass("woocommerce-checkout")) {
      consoleLog("Abort getting address. Not in checkout page");
      return;
    }

    const billingAddress = {
      firstName: $('input[name="billing_first_name"]').val(),
      lastName: $('input[name="billing_last_name"]').val(),
      company: $('input[name="billing_company"]').val(),
      line1: $('input[name="billing_address_1"]').val(),
      line2: $('input[name="billing_address_2"]').val(),
      city: $('input[name="billing_city"]').val(),
      state: $('select[name="billing_state"]').val(),
      postalCode: $('input[name="billing_postcode"]').val(),
      countryCode: $('select[name="billing_country"]').val(),
    };

    const shippingAddress = {
      firstName: $('input[name="shipping_first_name"]').val(),
      lastName: $('input[name="shipping_last_name"]').val(),
      company: $('input[name="shipping_company"]').val(),
      line1: $('input[name="shipping_address_1"]').val(),
      line2: $('input[name="shipping_address_2"]').val(),
      city: $('input[name="shipping_city"]').val(),
      state: $('select[name="shipping_state"]').val(),
      postalCode: $('input[name="shipping_postcode"]').val(),
      countryCode: $('select[name="shipping_country"]').val(),
    };

    // Check if the "Ship to a different address?" checkbox is checked
    const isShippingInfoChecked = document.querySelector(
      "#ship-to-different-address-checkbox"
    )?.checked;

    return isShippingInfoChecked ? shippingAddress : billingAddress;
  }

  /**
   * Handle user clicking the pfm button
   */
  async function onClickPayForMeInCartPage(e) {
    consoleLog("onClickPayForMeInCartPage");
    e.preventDefault();

    showModalDialog(COMPONENT_STORE_CHECKOUT);
  }

  /**
   * Handle user clicking the pfm button in the checkout page
   */
  async function onClickPayForMeInCheckoutPage(e) {
    consoleLog("onClickPayForMeInCheckoutPage");
    e.preventDefault();

    const address = getShippingAddressEnteredOnWcCheckoutPage();
    showModalDialog(COMPONENT_STORE_CHECKOUT, address);
  }

  function isPayForMeGatewaySelected() {
    const selectedGateway = jQuery(
      "input[name='payment_method']:checked"
    ).val();
    return selectedGateway === PAYFORME_GATEWAY_ID;
  }

  function observeChangesInWcCheckoutPage() {
    const defaultDisplay = $("#place_order").css("display");

    hideOrShowButtonBasedOnSelectedGateway();

    $("body").on("updated_checkout", function (data) {
      hideOrShowButtonBasedOnSelectedGateway();
    });

    $(document).on("change", 'input[name="payment_method"]', function () {
      hideOrShowButtonBasedOnSelectedGateway();
    });

    function hideOrShowButtonBasedOnSelectedGateway() {
      if (isPayForMeGatewaySelected()) {
        $("#place_order").css("display", "none");
        $("#pfm_paynow_container").show();
      } else {
        $("#place_order").css("display", defaultDisplay);
        $("#pfm_paynow_container").hide();
      }
    }
  }

  function observeWindowEvents() {
    document.addEventListener(
      "dispatch-event-plugin",
      (event) => {
        const eventData = event["detail"]?.data;
        consoleLog(
          "WC window received eventDetail " +
            JSON.stringify(eventData) +
            " from origin " +
            event.origin
        );

        if (eventData?.action === PAYFORME_SHIPPING_ADDRESS_ACTION) {
          fetchAndDisplayPaymentLinkData(eventData);
        }
      },
      false
    );
  }
});

/**
 * Logs to console if debug logging is enabled.
 * Allows turning logging on/off from a single variable.
 * @param {string} msg to log
 */
function consoleLog(msg) {
  if (!DEBUG_LOG) {
    return;
  }
  if (msg) {
    console.log(msg);
  } else {
    console.log("nothing - should not happen");
  }
}

/**
 * Show the specified component in a modal dialog.
 *
 * This is achieved by dispatching a document event that is
 * handled by the BaseModalContainer.
 * @param {string} componentName
 * @param {any} data optional data passed to the component
 */
function showModalDialog(componentName, data) {
  const customEvent = new CustomEvent("dispatch-event-modal", {
    detail: {
      componentName: componentName,
      data: data,
    },
  });
  document.dispatchEvent(customEvent);
}

/**
 * Dispatches error to the specified component dialog.
 *
 * @param {string} componentName
 * @param {string} errorMsg the error msg to display to the user?
 */
function dispatchErrorToComponent(componentName, errorMsg) {
  const customEvent = new CustomEvent(componentName, {
    detail: {
      error: "" + errorMsg,
    },
  });
  document.dispatchEvent(customEvent);
}
