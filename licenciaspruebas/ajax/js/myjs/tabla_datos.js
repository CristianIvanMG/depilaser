// pagefunction	
// DOM Position key index //	
//	l - Length changing (dropdown)
//	f - Filtering input (search)
//	t - The Table! (datatable)
//	i - Information (records)
//	p - Pagination (paging)
//	r - pRocessing 
//	< and > - div elements
//	<"#id" and > - div with an id
//	<"class" and > - div with a class
//	<"#id.class" and > - div with an id and class
	
//	Also see: http://legacy.datatables.net/usage/features
//

/*
var responsiveHelper;
var breakpointDefinition = {
    tablet: 1024,
    phone : 480
};
*/

var tabla = $('#tabla_datos').DataTable({
    colReorder: false, 
    ordering: true,
    order: [[ 0, 'asc' ]],
    bFilter: true,
	bInfo: true,
	//bLengthChange: false,
    lengthMenu: [[25, 50, 100, -1], [25, 50, 100, "Todo"]],
    paging: true,
    bStateSave: false, // saves sort state using localStorage
    //scrollY: '50vh',
    scrollX: true,
    searching: true,
    autoWidth : true,
    language: {
        //url: "http://cdn.datatables.net/plug-ins/1.10.16/i18n/Spanish.json"
    },
    dom: "<'dt-toolbar'<'col-xs-12 col-sm-6 hidden-xs'f >l<'col-sm-6 col-xs-12 hidden-xs'<'toolbar'>> r >" + "t" + "<'dt-toolbar-footer'<'col-sm-6 col-xs-12 hidden-xs'i><'col-xs-12 col-sm-6'p>> "
    /*,
    preDrawCallback: function () {
        // Initialize the responsive datatables helper once.
        if (!responsiveHelper) {
            responsiveHelper = new ResponsiveDatatablesHelper($('#tabla_datos'), breakpointDefinition);
        }
    },
    rowCallback    : function (nRow) {
        responsiveHelper.createExpandIcon(nRow);
    },
    drawCallback   : function (oSettings) {
        responsiveHelper.respond();
    }
    //"dom": '<"top"fp>lrt<"bottom"ip><"clear">'
    //columnDefs: [ { className: 'CeldaNegrita', "targets": [ 0, 12 ] }, 
    //           { className: 'CeldaTextoLargo', "targets": [ 4 ] } ] 
      */  
});

var id_seleccionado = 0;
var index_seleccionado = -1;

$('#tabla_datos tbody').on( 'click', 'tr', function () {
    
    index_seleccionado = this;
    id_seleccionado = tabla.cell(index_seleccionado, 1).data(); //conseguimos el valos de la celda deseada
    //alert(id_seleccionado);
    if ( $(this).hasClass('selected') ) {
        $(this).removeClass('selected');
    }
    else {
        $('#tabla_datos').DataTable().$('tr.selected').removeClass('selected');
        $(this).addClass('selected');
    }
} );
    
	 //loadScript("ajax/js/myjs/tabla_datos.js");
	 //loadScript("ajax/js/myjs/productos.js", consulta_catalogo_productos());	
 
    //pageSetUp();

      
//$('#tabla_datos thead th input[type=text]').each( function () {
    //var title = $('#tabla_datos thead th').eq( $(this).index() ).text();
    //$(this).html( '<input type="text" placeholder="Buscar '+title+'" />' );
//} );

// Apply the filter
/*
$("#tabla_datos thead th input[type=text]").on( 'keyup change', function () {
        tabla.column( $(this).parent().index()+':visible' ).search( this.value ).draw();
} );
*/  

//no funciona correctamente
/*
$('#tabla_datos tfoot th').each( function () {
    var title = $('#tabla_datos thead th').eq( $(this).index() ).text();
    $(this).html( '<input type="text" class="form-control" placeholder="Filtrar" />' );
} );


//aplicamos la búsqueda
tabla.columns().every( function () {
    var that = this;
    $( 'input', this.footer() ).on( 'keyup change', function () {
        tabla.column( $(this).parent().index()+':visible' ).search( this.value ).draw();
    } );
} );
*/
