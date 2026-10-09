// Form handling: submissions go to our own handler on this site first (/forms/submit.php).
// If that is unavailable, they go to the Formspree backup so no enquiry is lost.
var GAURAV_FORM_ENDPOINT = "https://formspree.io/f/mdekwrzq";

window.gbPost = function (payload) {
    payload.page = location.pathname;
    var body = JSON.stringify(payload);
    function backup() {
        return fetch(GAURAV_FORM_ENDPOINT, { method: "POST", headers: { "Accept": "application/json", "Content-Type": "application/json" }, body: body });
    }
    return fetch("/forms/submit.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: body })
        .then(function (res) { return res.status >= 500 ? backup() : res; })
        .catch(function () { return backup(); });
};

function gbAjax(o) {
    var payload = o.data;
    payload.page = location.pathname;
    $.ajax({
        url: "/forms/submit.php", type: "POST", contentType: "application/json", dataType: "json",
        data: JSON.stringify(payload), cache: false,
        success: o.success, complete: o.complete,
        error: function (xhr) {
            if (xhr.status === 0 || xhr.status >= 500) {
                $.ajax({ url: GAURAV_FORM_ENDPOINT, type: "POST", dataType: "json", headers: { "Accept": "application/json" }, data: payload, cache: false, success: o.success, error: o.error });
            } else if (o.error) { o.error(xhr); }
        }
    });
}

$(function () {

    $("#contactForm input, #contactForm textarea").jqBootstrapValidation({
        preventSubmit: true,
        submitError: function ($form, event, errors) {
        },
        submitSuccess: function ($form, event) {
            event.preventDefault();
            if ($("#hpField").val()) { return; }
            var name = $("input#name").val();
            var email = $("input#email").val();
            var subject = $("#subject").val();
            var message = $("textarea#message").val();

            $this = $("#sendMessageButton");
            $this.prop("disabled", true);

            gbAjax({
                data: {
                    name: name,
                    email: email,
                    _replyto: email,
                    subject: subject,
                    message: message
                },
                cache: false,
                success: function () {
                    $('#success').html("<div class='alert alert-success'>");
                    $('#success > .alert-success').html("<button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;")
                            .append("</button>");
                    $('#success > .alert-success')
                            .append("<strong>Your message has been sent. </strong>");
                    $('#success > .alert-success')
                            .append('</div>');
                    $('#contactForm').trigger("reset");
                },
                error: function () {
                    $('#success').html("<div class='alert alert-danger'>");
                    $('#success > .alert-danger').html("<button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;")
                            .append("</button>");
                    $('#success > .alert-danger').append($("<strong>").text("Sorry " + name + ", it seems that our mail server is not responding. Please try again later, or email higaurav@gauravbhattnagar.com directly."));
                    $('#success > .alert-danger').append('</div>');
                },
                complete: function () {
                    setTimeout(function () {
                        $this.prop("disabled", false);
                    }, 1000);
                }
            });
        },
        filter: function () {
            return $(this).is(":visible");
        },
    });

    $("a[data-toggle=\"tab\"]").click(function (e) {
        e.preventDefault();
        $(this).tab("show");
    });
});

$('#name').focus(function () {
    $('#success').html('');
});

// General enquiry form on contact.html (separate ids from the service-page forms)
$(function () {
    if (!$("#generalEnquiryForm").length) { return; }

    $("#generalEnquiryForm input, #generalEnquiryForm textarea").jqBootstrapValidation({
        preventSubmit: true,
        submitError: function ($form, event, errors) {
        },
        submitSuccess: function ($form, event) {
            event.preventDefault();
            if ($("#hpField").val()) { return; }
            var name = $("#genName").val();
            var email = $("#genEmail").val();
            var company = $("#genCompany").val();
            var source = $("#genSource").val();
            var message = $("#genMessage").val();

            var $btn = $("#genSendButton");
            $btn.prop("disabled", true);

            gbAjax({
                data: {
                    name: name,
                    email: email,
                    _replyto: email,
                    subject: "General enquiry from contact page",
                    company: company,
                    heard_about_via: source,
                    message: message
                },
                cache: false,
                success: function () {
                    $('#success').html("<div class='alert alert-success'>");
                    $('#success > .alert-success').html("<button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;")
                            .append("</button>");
                    $('#success > .alert-success')
                            .append("<strong>Your message has been sent. </strong>");
                    $('#success > .alert-success')
                            .append('</div>');
                    $('#generalEnquiryForm').trigger("reset");
                },
                error: function () {
                    $('#success').html("<div class='alert alert-danger'>");
                    $('#success > .alert-danger').html("<button type='button' class='close' data-dismiss='alert' aria-hidden='true'>&times;")
                            .append("</button>");
                    $('#success > .alert-danger').append($("<strong>").text("Sorry " + name + ", it seems that our mail server is not responding. Please try again later, or email higaurav@gauravbhattnagar.com directly."));
                    $('#success > .alert-danger').append('</div>');
                },
                complete: function () {
                    setTimeout(function () {
                        $btn.prop("disabled", false);
                    }, 1000);
                }
            });
        },
        filter: function () {
            return $(this).is(":visible");
        },
    });

    $('#genName').focus(function () {
        $('#success').html('');
    });
});
