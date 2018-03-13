jQuery(document).ready(function($) {

    $(document).on('click', '.p18aw-sync', function(e){
        e.preventDefault();

        if($(this).attr('disabled')) return false;

        var that = this,
            sync_action = $(that).data('sync');

        $(that).html('<img src="' + P18AW.asset_url + 'load.gif" />');
        $('.p18aw-sync').attr('disabled', true);

        $.ajax({
            method: "POST",
            url: ajaxurl,
            data: {
                action: "p18aw_request",
                nonce: P18AW.nonce,
                sync : sync_action
            },
            dataType : 'json'
        }).done(function(response) {

            if(response.status) {
                $('[data-sync-time="' + sync_action + '"]').text(response.timestamp);
            }

            $('.p18aw-sync').attr('disabled', false);
            $(that).html(P18AW.sync);
            
        });

    });
    
});