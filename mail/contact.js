// Formspree endpoint, notifications go to higaurav@gauravbhattnagar.com
// (same endpoint used in the "Find My Fit" quiz's own send step on qualify.html).
var GAURAV_FORM_ENDPOINT = "https://formspree.io/f/mdekwrzq";

$(function () {

    $("#contactForm input, #contactForm textarea").jqBootstrapValidation({
        preventSubmit: true,
        submitError: function ($form, event, errors) {
        },
        submitSuccess: function ($form, event) {
            event.preventDefault();
            var name = $("input#name").val();
            var email = $("input#email").val();
            var subject = $("#subject").val();
            var message = $("textarea#message").val();

            $this = $("#sendMessageButton");
            $this.prop("disabled", true);

            $.ajax({
                url: GAURAV_FORM_ENDPOINT,
                type: "POST",
                dataType: "json",
                headers: { "Accept": "application/json" },
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
            var name = $("#genName").val();
            var email = $("#genEmail").val();
            var company = $("#genCompany").val();
            var source = $("#genSource").val();
            var message = $("#genMessage").val();

            var $btn = $("#genSendButton");
            $btn.prop("disabled", true);

            $.ajax({
                url: GAURAV_FORM_ENDPOINT,
                type: "POST",
                dataType: "json",
                headers: { "Accept": "application/json" },
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
