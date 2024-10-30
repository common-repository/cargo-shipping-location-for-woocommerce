$ = window.jQuery;
let markersArray = [];

function setMarker(lat,long) {
    markersArray.forEach(function(marker) {
        // TODO rebuild with distribution point
        if(
            (marker.getPosition().lat() == Math.round(lat * 100) / 100 && marker.getPosition().lng() == Math.round(long * 100) / 100)
            ||
            (marker.getPosition().lat() == lat && marker.getPosition().lng() == long)
        ){
            google.maps.event.trigger(marker, 'click');
        }
    });
}

function toRad(Value) {
    return Value * Math.PI / 180;
}


(function($) {
    let mainJson;
    let customerAddress = null;

    /**
     * Change all the makers after select
     */
    function clearSelectedMarker() {

        markersArray.forEach(function(marker) {
            const icon = {
                url: $('#default_markers').val(), // url
                scaledSize: new google.maps.Size(60, 60), // scaled size
            };
            marker.setIcon(icon);
        });
    }

    function addLocationSection(shippingMethod){

        if(shippingMethod?.split(':')[0] === 'woo-baldarp-pickup') {
            if( Cookies.get('cargoPointID') != null ) {
                // $(".selected_cargo").html(decodeURIComponent(escape(atob(Cookies.get('fullAddress')))));
            } else {
                if ($('#mapmodelcargo').hasClass("hidden")) {
                    checkLocationSet(shippingMethod);
                } else {
                    $("#modal-close").trigger('click');
                }
            }

        }
    }

    function resetCargo() {
        Cookies.set("cargoLatitude","",{expires: -1,path: '/'});
        Cookies.set("cargoLongitude","",{expires: -1,path: '/'});
        Cookies.set("cargoPointID","",{expires: -1,path: '/'});
        Cookies.set("fullAddress","",{expires: -1,path: '/'});
        Cookies.set('cargoPointName',"",{expires: -1,path: '/'});
        Cookies.set('CargoCityName',"",{expires: -1,path: '/'});
        Cookies.set('cargoStreetName',"",{expires: -1,path: '/'});
        Cookies.set('cargoStreetNum',"",{expires: -1,path: '/'});
        Cookies.set('cargoComment',"",{expires: -1,path: '/'});
    }

    function changeShipping(){
        setTimeout(function(){
            if(Cookies.get('cargoPointID') != null) {
                // $(".selected_cargo").html(decodeURIComponent(escape(atob(Cookies.get('fullAddress')))));
            }else{
                if($('#mapmodelcargo').hasClass("hidden")){
                    checkLocationSet();
                }else{
                    $("#modal-close").trigger('click');
                }
            }
            $('#mapbutton').css('pointer-events','all');
            $("div.blockUI").removeClass('blockOverlay');
            $("div.blockUI").removeAttr('id');
            $("div.blockUI").hide();
            $("#mapbutton").parent('li').css("margin-bottom","auto");
        },5000);
    }

    function checkLocationSet(shippingMethod = '') {
        if($("#shipping_method").length) {
            shippingMethod = shippingMethod != '' ? shippingMethod : $('input[name="shipping_method[0]"]:checked').val();

            if(shippingMethod.split(':')[0] == 'woo-baldarp-pickup') {
                if($('#mapmodelcargo').hasClass("hidden")){
                    if(Cookies.get('cargoPointID') == null) {
                        $('#mapbutton').css('pointer-events','all');
                        $('#mapbutton').css('display','block');
                        $('.wc_card_shipping_header_cargo').css('display','block');
                    }
                }
                $('#mapbutton').css('pointer-events','all');
                $("#mapbutton").parent('li').css("margin-bottom","auto");
            }
        }
    }

    async function getCustomerLocation()
    {
        let street1 = $('#shipping_address_1').val() ?? $('#billing_address_1').val();
        let street2 = $('#shipping_address_2').val() ?? $('#billing_address_2').val();
        let city = $('#shipping_city').val() ?? $('#billing_city').val();
        let data = {
            action: 'cslfw_cargo_geocoding',
            address: `${street1} ${street2}, ${city}`
        };
        console.log('getCustomerLocation', data)
        await $.ajax({
            type: 'POST',  // http method
            dataType: "json",
            url: baldarp_obj.ajaxurl,
            data: data,
            success: function (response, status, xhr) {
                if (response.errors === false) {
                    if (response.data.results.length > 0) {
                        let location = response.data.results[0].geometry.location;

                        customerAddress = {
                            Latitude: location.lat,
                            Longitude: location.lng
                        }
                    } else {
                        customerAddress = null
                    }
                } else {
                    customerAddress = null
                }
            }
        })
    }

    // new map script
    function getlocationfromSearch(lat,long) {
        $.ajax('https://api.cargo.co.il/konimbo.php', {
            type: 'POST',  // http method
            data: {
                action: 'cargo_get_close_locations',
                lat: lat,
                long : long ,
                // datalogics_shipping_id : datalogics_shipping_id
            },  // data to submit
            success: function (data, status, xhr) {
                $(".cargo_location_list").html(data);
                $(".cargo_location_list").show();
                $(".js-toggle-locations-list").show();
                $('#map').addClass('opened-list')
                if( data != '' ) {
                    $( ".cargo_location_list_row" ).first().trigger('click');
                }
                $('.cargo_location_list_row .button').hide();
            },
            error: function (jqXhr, textStatus, errorMessage) {
                console.log(textStatus);
            }
        });
    }

    function setPointCookie(pointData) {
        Cookies.set('cargoLatitude', pointData.Latitude, {expires: 10,path: '/'});
        Cookies.set('cargoLongitude', pointData.Longitude,{expires: 10,path: '/'});
        Cookies.set('cargoPointID', pointData.DistributionPointID, {expires: 10,path: '/'})
        Cookies.set('cargoPointName',pointData.DistributionPointName,{expires: 10,path: '/'})
        Cookies.set('CargoCityName', pointData.CityName,{expires: 10,path: '/'})
        Cookies.set('cargoStreetName', pointData.StreetName,{expires: 10,path: '/'})
        Cookies.set('cargoStreetNum', pointData.StreetNum,{expires: 10,path: '/'})
        Cookies.set('cargoComment', pointData.Comment,{expires: 10,path: '/'})
    }


    /* Init Google map on the modal popup */
    function initMap() {
        if(Cookies.get('cargoPointID') != null) {
            var myloc = new google.maps.LatLng(Cookies.get('cargoLatitude'),Cookies.get('cargoLongitude'));
            // $(".selected_cargo").html(decodeURIComponent(escape(atob(Cookies.get('fullAddress')))));
            // $("#FlyingCargo_loc_name").html(decodeURIComponent(escape(atob(Cookies.get('fullAddress')))));
            $(".selected_cargo").show();
        } else {
            var myloc = new google.maps.LatLng(32.4631, 35.0433);
        }
        var mapOptions = {
            zoom: 10,
            center: myloc,
            scrollwheel: false,
            gestureHandling:'greedy'
        };
        const styledMapType = new google.maps.StyledMapType(
            [
                {
                    "featureType": "water",
                    "stylers": [
                        {
                            "saturation": 43
                        },
                        {
                            "lightness": -11
                        },
                        {
                            "hue": "#0088ff"
                        }
                    ]
                },
                {
                    "featureType": "road",
                    "elementType": "geometry.fill",
                    "stylers": [
                        {
                            "hue": "#ff0000"
                        },
                        {
                            "saturation": -100
                        },
                        {
                            "lightness": 99
                        }
                    ]
                },
                {
                    "featureType": "road",
                    "elementType": "geometry.stroke",
                    "stylers": [
                        {
                            "color": "#808080"
                        },
                        {
                            "lightness": 54
                        }
                    ]
                },
                {
                    "featureType": "landscape.man_made",
                    "elementType": "geometry.fill",
                    "stylers": [
                        {
                            "color": "#ece2d9"
                        }
                    ]
                },
                {
                    "featureType": "poi.park",
                    "elementType": "geometry.fill",
                    "stylers": [
                        {
                            "color": "#ccdca1"
                        }
                    ]
                },
                {
                    "featureType": "road",
                    "elementType": "labels.text.fill",
                    "stylers": [
                        {
                            "color": "#767676"
                        }
                    ]
                },
                {
                    "featureType": "road",
                    "elementType": "labels.text.stroke",
                    "stylers": [
                        {
                            "color": "#ffffff"
                        }
                    ]
                },
                {
                    "featureType": "poi",
                    "stylers": [
                        {
                            "visibility": "off"
                        }
                    ]
                },
                {
                    "featureType": "landscape.natural",
                    "elementType": "geometry.fill",
                    "stylers": [
                        {
                            "visibility": "on"
                        },
                        {
                            "color": "#dde3e3"
                        }
                    ]
                },
                {
                    "featureType": "poi.park",
                    "stylers": [
                        {
                            "visibility": "on"
                        }
                    ]
                },
                {
                    "featureType": "poi.sports_complex",
                    "stylers": [
                        {
                            "visibility": "on"
                        }
                    ]
                },
                {
                    "featureType": "poi.medical",
                    "stylers": [
                        {
                            "visibility": "on"
                        }
                    ]
                },
                {
                    "featureType": "poi.business",
                    "stylers": [
                        {
                            "visibility": "simplified"
                        }
                    ]
                }
            ],
            { name: "Styled Map" }
        );
        var map = new google.maps.Map(document.getElementById('map'),mapOptions);

        var infowindow = new google.maps.InfoWindow();
        var marker, customerMarker, i;
        map.mapTypes.set("styled_map", styledMapType);
        map.setMapTypeId("styled_map");

        $.each(mainJson, function(index) {
            marker = new google.maps.Marker({
                map: map,
                position: new google.maps.LatLng(mainJson[index].Latitude, mainJson[index].Longitude),
            });
            marker.DistributionPointID = mainJson[index].DistributionPointID

            icon = {
                url: $('#default_markers').val(), // url
                scaledSize: new google.maps.Size(60, 60), // scaled size
            };
            marker.setIcon(icon);
            markersArray.push(marker);

            if (customerAddress) {
                let customerLocation = new google.maps.LatLng(customerAddress?.Latitude, customerAddress?.Longitude);
                customerMarker = new google.maps.Marker({
                    map: map,
                    position: customerLocation,
                });
                customerIcon = {
                    url: $('#customer_marker').val(), // url
                    scaledSize: new google.maps.Size(60, 60), // scaled size
                };
                customerMarker.setIcon(customerIcon);

                markersArray.push(marker);

                map.setZoom(14);
                map.setCenter(customerLocation);
            }
            google.maps.event.addListener(marker, 'click', (function(marker,i)  {
                return function() {
                    infowindow.close();
                    clearSelectedMarker();

                    infowindow = new google.maps.InfoWindow({content: '<div id="content'+mainJson[index].DistributionPointID+'" style="direction:rtl;padding-left: 15px; text-align:right;max-width:240px;margin: 0;">'+
                            '<div id="siteNotice"></div>'+
                            '<h5 style="margin: 0;">'+mainJson[index].DistributionPointName+'</h5>'+
                            '<div id="bodyContent">'+
                            '<p>'+mainJson[index].StreetNum+' , '+mainJson[index].StreetName+' '+mainJson[index].CityName+' '+ mainJson[index].Comment +' </p>'+
                            '</div>'+
                            '<button type="button" class="selected-location btn button wp-element-button" id="FlyingCargo_confirm" data-lat="'+mainJson[index].Latitude+'" data-long="'+mainJson[index].Longitude+'" data-fulladd="'+btoa(unescape(encodeURIComponent('<div>'+mainJson[index].DistributionPointName+'</div> <div>'+mainJson[index].StreetNum+' , '+mainJson[index].StreetName+' '+mainJson[index].CityName+mainJson[index].Comment+'</div>')))+'" data-disctipointid="'+mainJson[index].DistributionPointID+'" data-pointname="'+mainJson[index].DistributionPointName+'" data-city="'+mainJson[index].CityName+'" data-street="'+mainJson[index].StreetName+'" data-streetnum="'+mainJson[index].StreetNum+'" data-comment="'+mainJson[index].Comment+'" data-locationname="'+mainJson[index].DistributionPointName+'" data-cargoph="'+''+'">בחירה וסיום</button>'+
                            '</div>' });
                    infowindow.open(map,marker);
                    const icon = {
                        url: $('#selected_marker').val(), // url
                        scaledSize: new google.maps.Size(60, 60), // scaled size
                    };

                    marker.setIcon(icon);
                    map.setZoom(12);
                    map.setCenter(marker.getPosition());
                }
            })(marker, i));

            if(Cookies.get('cargoPointID') != null) {
                if(mainJson[index].DistributionPointID == Cookies.get('cargoPointID')) {
                    icon = {
                        url: $('#selected_marker').val(), // url
                        scaledSize: new google.maps.Size(60, 60), // scaled size
                    };
                    marker.setIcon(icon);
                }
            }

        })
    }


    $ = window.jQuery;
    $.ajax({
        type: "post",
        url: baldarp_obj.ajaxurl,
        data: {action: "get_delivery_location"},
        success: function(msg){
            $("div.blockUI").removeClass('blockOverlay');
            $("div.blockUI").removeAttr('id')
            $("div.blockUI").hide();

            var msgnew = JSON.parse(msg);
            if(msgnew.data == 0) {
                return false;
            }
            mainJson = JSON.parse(msgnew.dataval);
            $.each(mainJson, function(index) {
                if(mainJson[index].DistributionPointID == Cookies.get('cargoPointID')) {
                    $('#DistributionPointID').val(mainJson[index].DistributionPointID);
                    $('#DistributionPointName').val(mainJson[index].DistributionPointName);
                    $('#CityName').val(mainJson[index].CityName);
                    $('#StreetName').val(mainJson[index].StreetName);
                    $('#StreetNum').val(mainJson[index].StreetNum);
                    $('#Comment').val(mainJson[index].Comment);
                    $('#Latitude').val(mainJson[index].Latitude);
                    $('#Longitude').val(mainJson[index].Longitude);
                }
            });
            // $('#mapbutton').css('pointer-events','all');
            $('#mapbutton').show()
            if($('#mapmodelcargo').hasClass("hidden")){
                if($("#shipping_method").length) {
                    addLocationSection(msgnew.shippingMethod);
                }
            }
        }
    });
    setTimeout(function(){
        if($("div.blockUI").hasClass('blockOverlay')) {
            $("div.blockUI").removeClass('blockOverlay');
            $("div.blockUI").removeAttr('id')
            $("div.blockUI").hide();
        }
    },5000);

    $(document).ready(function() {
        if($('body').hasClass('woocommerce-cart')) {
            resetCargo();
        }
        if($('body').hasClass('woocommerce-checkout')) {
            $('#order_review').prepend('<div class="blockUI" style=""></div>');
            $("div.blockUI").attr('id','overlay')
            $("div.blockUI").addClass('blockOverlay');
            $("#mapbutton").parent('li').css("margin-bottom","auto");
        }

        // addLocationSection();
    });

    $(document).on('click','.marker-click',function(){
        var markerID = $(this).data('id');
        google.maps.event.trigger(markersArray[markerID], 'click');
    });

    $(document).on('click','.js-modal-close',function () {
        $(this).closest('.modal').addClass('hidden');
        $(this).closest('.modal').removeClass('show');
    });

    $(document).on('click','#mapbutton', async function(e){
        e.preventDefault();
        console.log('mapbutton clicked')
        try {
            await getCustomerLocation();

            google.maps.event.trigger(map, 'resize');
            initMap();
            $('.modal').each(function(){
                if(!$(this).hasClass('descript')){
                    $("#mapmodelcargo").removeClass('hidden');
                    $("#mapmodelcargo").addClass('show');
                }
            });
        } catch (error) {
            console.log(error)
        }
    });

    $(document).on('click','.open-how-it-works',function(){
        $(".descript").show();
        $(".descript").removeClass('hidden');
        $(".descript").addClass('show');
    });

    $(document).on('updated_checkout', function() {
        let cityParent = $('#cargo_city').closest('.cargo-select-wrap');
        let pointParent = $('#cargo_pickup_point').closest('.cargo-select-wrap');
        $('#cargo_city').select2({
            dropdownParent: cityParent,
            dropdownCss: {
                top: cityParent.height() // Adjust the value as per your requirements
            }
        })
        $('#cargo_pickup_point').select2({
            minimumResultsForSearch: -1,
            dropdownParent: pointParent,
        })
        // $('#cargo_city').select2({dropdownParent: $('#shipping_method')});
        // $('#cargo_pickup_point').select2({minimumResultsForSearch: -1, dropdownParent: $('#shipping_method')});
        // $('#cargo_city, #cargo_pickup_point').focusout();
    })

    $(document).on('change','.shipping_method', function() {
        var shippingMethod  = $(this).val().split(':');

        if(shippingMethod[0] == 'woo-baldarp-pickup') {
            $("div.blockUI").attr('id','overlay')
            $("div.blockUI").addClass('blockOverlay');
            $("div.blockUI").show();

            $("#mapbutton").show();
            // $("#mapbutton").trigger('click');
            $(".selected_cargo").show();
            changeShipping();
        } else {
            $("#mapbutton").hide();
            $(".selected_cargo").hide();
        }
    });

    $(document).on('change', '#cargo_city', function() {
        resetCargo();
        Cookies.set('CargoCityName_dropdown', $(this).val(), {expires: 10,path: '/'})
        $('#CityName').val($(this).val());
        let data = {
            action: 'cslfw_get_points_by_city',
            city: $(this).val()
        }
        $.ajax({
            type: 'POST',  // http method
            dataType: "json",
            url: baldarp_obj.ajaxurl,
            data: data,
            success: function (response, status, xhr) {
                $('#cargo_pickup_point').parent().parent().find('.woocommerce-info').remove();

                if ( response.errors === false ) {
                    if ( response.data.length > 0 ) {
                        setPointCookie(response.data[0]);
                    } else {
                        $('#cargo_pickup_point').parent().hide();
                        $('#cargo_pickup_point').parent().parent().find('.woocommerce-info').show();
                    }
                } else {
                    $('#cargo_pickup_point').parent().hide();
                    alert('response.error');
                }
                setTimeout(function() {
                    $( document.body ).trigger( 'update_checkout' );
                }, 200)
            },
            error: function (jqXhr, textStatus, errorMessage) {
                console.log(textStatus);
            }
        });

    })

    $(document).on('change', '#cargo_pickup_point', function() {
        Cookies.set('cargoPointID', $(this).val(), {expires: 10,path: '/'})
        Cookies.set('CargoCityName', $('#cargo_city option:selected').attr('value'), {expires: 10,path: '/'});

        setTimeout(function() {
            $(document.body).trigger('update_checkout');
        }, 100);
    })

    $(document).on('submit','form.checkout', function() {
        $('.shipping_method').each(function(){
            if($('input[name="shipping_method[0]"]:checked').val().split(':')[0] == 'woo-baldarp-pickup') {
                if(Cookies.get('cargoPointID') == null) {
                    return false;
                }
            }
        });
    });

    /**
     * insert value after click on the confirm location
     */
    $(document).on('click','#FlyingCargo_confirm',function () {
        $('#FlyingCargo_loc_name').html($(this).attr("data-locationName"));
        $('.selected_cargo').html(decodeURIComponent(escape(atob($(this).attr("data-fullAdd")))));
        $('#DistributionPointID').val($(this).attr("data-disctiPointID"));
        $('#DistributionPointName').val($(this).attr("data-pointName"));
        $('#CityName').val($(this).attr("data-city"));
        $('#StreetName').val($(this).attr("data-street"));
        $('#StreetNum').val($(this).attr("data-streetNum"));
        $('#Comment').val($(this).attr("data-comment"));
        $('#Latitude').val($(this).attr("data-lat"));
        $('#Longitude').val($(this).attr("data-long"));
        Cookies.set('cargoLatitude', $(this).attr("data-lat"), {expires: 10,path: '/'});
        Cookies.set('cargoLongitude', $(this).attr("data-long"),{expires: 10,path: '/'});
        Cookies.set('cargoPointID',$(this).attr("data-disctiPointID"),{expires: 10,path: '/'})
        Cookies.set('cargoPointName',$(this).attr("data-pointName"),{expires: 10,path: '/'})
        Cookies.set('CargoCityName',$(this).attr("data-city"),{expires: 10,path: '/'})
        Cookies.set('cargoStreetName',$(this).attr("data-street"),{expires: 10,path: '/'})
        Cookies.set('cargoStreetNum',$(this).attr("data-streetNum"),{expires: 10,path: '/'})
        Cookies.set('cargoComment',$(this).attr("data-comment"),{expires: 10,path: '/'})
        Cookies.set('fullAddress',$(this).attr("data-fullAdd"),{expires: 10,path: '/'})
        $('.selected_cargo').show();
        $('.modal').removeClass('show');
        $('.modal').addClass('hidden');
        $('#mapbutton').css('pointer-events','all');
    });


    // Execute a function when the user releases a key on the keyboard
    $(".cargo_input_div input").on("keyup", function (event) {
        if (event.keyCode === 13) {
            $(this).siblings("button").click();
        }
    });

    $(".cargo_address_check").on("click", function () {
        let address = $(this).siblings("input").val();
        let data = {
            address : address,
            action: 'cslfw_cargo_geocoding'
        };

        $.ajax({
            type: 'POST',  // http method
            dataType: "json",
            url: baldarp_obj.ajaxurl,
            data: data,
            success: function (response, status, xhr) {
                if (response.errors === false) {
                    if (response.data.results.length > 0) {
                        let location = response.data.results[0].geometry.location;
                        getlocationfromSearch(location.lat, location.lng)
                    } else {
                        alert('No results found by your search');
                    }

                } else {
                    alert(response.message);
                }
            },
            error: function(e) {
                alert('something went wrong');
            }
        })
    })

    jQuery(document).on('click', '.js-toggle-locations-list', function(e) {
        e.preventDefault();
        jQuery('.cargo_location_list').hide();
        jQuery(this).hide();
        $('#map').removeClass('opened-list')
    })
})(window.jQuery)
