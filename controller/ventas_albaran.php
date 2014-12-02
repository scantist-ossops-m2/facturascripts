<?php
/*
 * This file is part of FacturaSctipts
 * Copyright (C) 2014  Carlos Garcia Gomez  neorazorx@gmail.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 * 
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'base/fs_pdf.php';
require_model('albaran_cliente.php');
require_model('articulo.php');
require_model('asiento.php');
require_model('asiento_factura.php');
require_model('cliente.php');
require_model('ejercicio.php');
require_model('factura_cliente.php');
require_model('familia.php');
require_model('fs_var.php');
require_model('impuesto.php');
require_model('partida.php');
require_model('regularizacion_iva.php');
require_model('serie.php');
require_model('subcuenta.php');
require_once 'extras/phpmailer/class.phpmailer.php';
require_once 'extras/phpmailer/class.smtp.php';

class ventas_albaran extends fs_controller
{
   public $agente;
   public $albaran;
   public $cliente;
   public $cliente_s;
   public $ejercicio;
   public $familia;
   public $impuesto;
   public $nuevo_albaran_url;
   public $serie;
   
   public function __construct()
   {
      parent::__construct(__CLASS__, FS_ALBARAN.' de cliente', 'ventas', FALSE, FALSE);
   }
   
   protected function process()
   {
      $this->ppage = $this->page->get('ventas_albaranes');
      $this->agente = FALSE;
      
      /// desactivamos la barra de botones
      $this->show_fs_toolbar = FALSE;
      
      $albaran = new albaran_cliente();
      $this->albaran = FALSE;
      $this->cliente = new cliente();
      $this->cliente_s = FALSE;
      $this->ejercicio = new ejercicio();
      $this->familia = new familia();
      $this->impuesto = new impuesto();
      $this->nuevo_albaran_url = FALSE;
      $this->serie = new serie();
      
      /**
       * Comprobamos si el usuario tiene acceso a nueva_venta,
       * necesario para poder añadir líneas.
       */
      if( $this->user->have_access_to('nueva_venta', FALSE) )
      {
         $nuevoalbp = $this->page->get('nueva_venta');
         if($nuevoalbp)
            $this->nuevo_albaran_url = $nuevoalbp->url();
      }
      
      if( isset($_POST['idalbaran']) )
      {
         $this->albaran = $albaran->get($_POST['idalbaran']);
         $this->modificar();
      }
      else if( isset($_GET['id']) )
      {
         $this->albaran = $albaran->get($_GET['id']);
      }
      
      if( $this->albaran AND isset($_GET['imprimir']) )
      {
         /// cargamos el cliente
         $this->cliente_s = $this->cliente->get($this->albaran->codcliente);
         
         if($_GET['imprimir'] == 'simple')
         {
            $this->generar_pdf_simple();
         }
         else
         {
            $this->generar_pdf_cuartilla();
         }
      }
      else if( $this->albaran )
      {
         $this->page->title = $this->albaran->codigo;
         
         /// cargamos el agente
         if( !is_null($this->albaran->codagente) )
         {
            $agente = new agente();
            $this->agente = $agente->get($this->albaran->codagente);
         }
         
         /// cargamos el cliente
         $this->cliente_s = $this->cliente->get($this->albaran->codcliente);
         
         /// comprobamos el albarán
         if( $this->albaran->full_test() )
         {
            if( isset($_GET['facturar']) AND isset($_GET['petid']) AND $this->albaran->ptefactura )
            {
               if( $this->duplicated_petition($_GET['petid']) )
               {
                  $this->new_error_msg('Petición duplicada. Evita hacer doble clic sobre los botones.');
               }
               else
                  $this->generar_factura();
            }
            
            /// comprobamos si se pueden enviar emails
            if( $this->empresa->can_send_mail() )
            {
               if( isset($_POST['email']) )
               {
                  $this->enviar_email();
               }
            }
         }
      }
      else
         $this->new_error_msg("¡".FS_ALBARAN." de cliente no encontrado!");
   }
   
   public function url()
   {
      if( !isset($this->albaran) )
      {
         return parent::url();
      }
      else if($this->albaran)
      {
         return $this->albaran->url();
      }
      else
         return $this->page->url();
   }
   
   private function modificar()
   {
      $this->albaran->numero2 = $_POST['numero2'];
      $this->albaran->observaciones = $_POST['observaciones'];
      
      if($this->albaran->ptefactura)
      {
         /// obtenemos los datos del ejercicio para acotar la fecha
         $eje0 = $this->ejercicio->get( $this->albaran->codejercicio );
         if($eje0)
         {
            $this->albaran->fecha = $eje0->get_best_fecha($_POST['fecha'], TRUE);
            $this->albaran->hora = $_POST['hora'];
         }
         else
            $this->new_error_msg('No se encuentra el ejercicio asociado al '.FS_ALBARAN);
         
         /// ¿cambiamos el cliente?
         if($_POST['cliente'] != $this->albaran->codcliente)
         {
            $cliente = $this->cliente->get($_POST['cliente']);
            if($cliente)
            {
               foreach($cliente->get_direcciones() as $d)
               {
                  if($d->domfacturacion)
                  {
                     $this->albaran->codcliente = $cliente->codcliente;
                     $this->albaran->cifnif = $cliente->cifnif;
                     $this->albaran->nombrecliente = $cliente->nombrecomercial;
                     $this->albaran->apartado = $d->apartado;
                     $this->albaran->ciudad = $d->ciudad;
                     $this->albaran->coddir = $d->id;
                     $this->albaran->codpais = $d->codpais;
                     $this->albaran->codpostal = $d->codpostal;
                     $this->albaran->direccion = $d->direccion;
                     $this->albaran->provincia = $d->provincia;
                     break;
                  }
               }
            }
            else
               die('No se ha encontrado el cliente.');
         }
         else
            $cliente = $this->cliente->get($this->albaran->codcliente);
         
         $serie = $this->serie->get($this->albaran->codserie);
         
         /// ¿cambiamos la serie?
         if($_POST['serie'] != $this->albaran->codserie)
         {
            $serie2 = $this->serie->get($_POST['serie']);
            if($serie2)
            {
               $this->albaran->codserie = $serie2->codserie;
               $this->albaran->irpf = $serie2->irpf;
               $this->albaran->new_codigo();
               
               $serie = $serie2;
            }
         }
         
         if( isset($_POST['numlineas']) )
         {
            $numlineas = intval($_POST['numlineas']);
            
            $this->albaran->neto = 0;
            $this->albaran->totaliva = 0;
            $this->albaran->totalirpf = 0;
            $this->albaran->totalrecargo = 0;
            $lineas = $this->albaran->get_lineas();
            $articulo = new articulo();
            
            /// eliminamos las líneas que no encontremos en el $_POST
            foreach($lineas as $l)
            {
               $encontrada = FALSE;
               for($num = 0; $num <= $numlineas; $num++)
               {
                  if( isset($_POST['idlinea_'.$num]) )
                  {
                     if($l->idlinea == intval($_POST['idlinea_'.$num]))
                     {
                        $encontrada = TRUE;
                        break;
                     }
                  }
               }
               if( !$encontrada )
               {
                  if( $l->delete() )
                  {
                     /// actualizamos el stock
                     $art0 = $articulo->get($l->referencia);
                     if($art0)
                        $art0->sum_stock($this->albaran->codalmacen, $l->cantidad);
                  }
                  else
                     $this->new_error_msg("¡Imposible eliminar la línea del artículo ".$l->referencia."!");
               }
            }
            
            /// modificamos y/o añadimos las demás líneas
            for($num = 0; $num <= $numlineas; $num++)
            {
               $encontrada = FALSE;
               if( isset($_POST['idlinea_'.$num]) )
               {
                  foreach($lineas as $k => $value)
                  {
                     /// modificamos la línea
                     if($value->idlinea == intval($_POST['idlinea_'.$num]))
                     {
                        $encontrada = TRUE;
                        $cantidad_old = $value->cantidad;
                        $lineas[$k]->cantidad = floatval($_POST['cantidad_'.$num]);
                        $lineas[$k]->pvpunitario = floatval($_POST['pvp_'.$num]);
                        $lineas[$k]->dtopor = floatval($_POST['dto_'.$num]);
                        $lineas[$k]->dtolineal = 0;
                        $lineas[$k]->pvpsindto = ($value->cantidad * $value->pvpunitario);
                        $lineas[$k]->pvptotal = ($value->cantidad * $value->pvpunitario * (100 - $value->dtopor)/100);
                        $lineas[$k]->descripcion = $_POST['desc_'.$num];
                        
                        $lineas[$k]->codimpuesto = NULL;
                        $lineas[$k]->iva = 0;
                        $lineas[$k]->recargo = 0;
                        $lineas[$k]->irpf = $this->albaran->irpf;
                        if( !$serie->siniva AND $cliente->regimeniva != 'Exento' )
                        {
                           $imp0 = $this->impuesto->get_by_iva($_POST['iva_'.$num]);
                           if($imp0)
                              $lineas[$k]->codimpuesto = $imp0->codimpuesto;
                           
                           $lineas[$k]->iva = floatval($_POST['iva_'.$num]);
                           $lineas[$k]->recargo = floatval($_POST['recargo_'.$num]);
                        }
                        
                        if( $lineas[$k]->save() )
                        {
                           $this->albaran->neto += $value->pvptotal;
                           $this->albaran->totaliva += $value->pvptotal * $value->iva/100;
                           $this->albaran->totalirpf += $value->pvptotal * $value->irpf/100;
                           $this->albaran->totalrecargo += $value->pvptotal * $value->recargo/100;
                           
                           if($lineas[$k]->cantidad != $cantidad_old)
                           {
                              /// actualizamos el stock
                              $art0 = $articulo->get($value->referencia);
                              if($art0)
                                 $art0->sum_stock($this->albaran->codalmacen, $cantidad_old - $lineas[$k]->cantidad);
                           }
                        }
                        else
                           $this->new_error_msg("¡Imposible modificar la línea del artículo ".$value->referencia."!");
                        break;
                     }
                  }
                  
                  /// añadimos la línea
                  if(!$encontrada AND intval($_POST['idlinea_'.$num]) == -1 AND isset($_POST['referencia_'.$num]))
                  {
                     $art0 = $articulo->get( $_POST['referencia_'.$num] );
                     if($art0)
                     {
                        $linea = new linea_albaran_cliente();
                        $linea->referencia = $art0->referencia;
                        $linea->descripcion = $_POST['desc_'.$num];
                        $linea->irpf = $this->albaran->irpf;
                        
                        if( !$serie->siniva AND $cliente->regimeniva != 'Exento' )
                        {
                           $imp0 = $this->impuesto->get_by_iva($_POST['iva_'.$num]);
                           if($imp0)
                              $linea->codimpuesto = $imp0->codimpuesto;
                           
                           $linea->iva = floatval($_POST['iva_'.$num]);
                           $linea->recargo = floatval($_POST['recargo_'.$num]);
                        }
                        
                        $linea->idalbaran = $this->albaran->idalbaran;
                        $linea->cantidad = floatval($_POST['cantidad_'.$num]);
                        $linea->pvpunitario = floatval($_POST['pvp_'.$num]);
                        $linea->dtopor = floatval($_POST['dto_'.$num]);
                        $linea->pvpsindto = ($linea->cantidad * $linea->pvpunitario);
                        $linea->pvptotal = ($linea->cantidad * $linea->pvpunitario * (100 - $linea->dtopor)/100);
                        
                        if( $linea->save() )
                        {
                           $this->albaran->neto += $linea->pvptotal;
                           $this->albaran->totaliva += $linea->pvptotal * $linea->iva/100;
                           $this->albaran->totalirpf += $linea->pvptotal * $linea->irpf/100;
                           $this->albaran->totalrecargo += $linea->pvptotal * $linea->recargo/100;
                           
                           /// actualizamos el stock
                           $art0->sum_stock($this->albaran->codalmacen, 0 - $linea->cantidad);
                        }
                        else
                           $this->new_error_msg("¡Imposible guardar la línea del artículo ".$linea->referencia."!");
                     }
                     else
                        $this->new_error_msg("¡Artículo ".$_POST['referencia_'.$num]." no encontrado!");
                  }
               }
            }
            
            /// redondeamos
            $this->albaran->neto = round($this->albaran->neto, FS_NF0);
            $this->albaran->totaliva = round($this->albaran->totaliva, FS_NF0);
            $this->albaran->totalirpf = round($this->albaran->totalirpf, FS_NF0);
            $this->albaran->totalrecargo = round($this->albaran->totalrecargo, FS_NF0);
            $this->albaran->total = $this->albaran->neto + $this->albaran->totaliva - $this->albaran->totalirpf + $this->albaran->totalrecargo;
            
            if( abs(floatval($_POST['atotal']) - $this->albaran->total) > .01 )
            {
               $this->new_error_msg("El total difiere entre el controlador y la vista (".$this->albaran->total.
                       " frente a ".$_POST['atotal']."). Debes informar del error.");
            }
         }
      }
      
      if( $this->albaran->save() )
      {
         $this->new_message(ucfirst(FS_ALBARAN)." modificado correctamente.");
         $this->new_change(ucfirst(FS_ALBARAN).' Cliente '.$this->albaran->codigo, $this->albaran->url());
      }
      else
         $this->new_error_msg("¡Imposible modificar el ".FS_ALBARAN."!");
   }
   
   private function generar_factura()
   {
      $factura = new factura_cliente();
      $factura->apartado = $this->albaran->apartado;
      $factura->automatica = TRUE;
      $factura->cifnif = $this->albaran->cifnif;
      $factura->ciudad = $this->albaran->ciudad;
      $factura->codagente = $this->albaran->codagente;
      $factura->codalmacen = $this->albaran->codalmacen;
      $factura->codcliente = $this->albaran->codcliente;
      $factura->coddir = $this->albaran->coddir;
      $factura->coddivisa = $this->albaran->coddivisa;
      $factura->tasaconv = $this->albaran->tasaconv;
      $factura->codejercicio = $this->albaran->codejercicio;
      $factura->codpago = $this->albaran->codpago;
      $factura->codpais = $this->albaran->codpais;
      $factura->codpostal = $this->albaran->codpostal;
      $factura->codserie = $this->albaran->codserie;
      $factura->direccion = $this->albaran->direccion;
      $factura->editable = FALSE;
      $factura->neto = $this->albaran->neto;
      $factura->nombrecliente = $this->albaran->nombrecliente;
      $factura->observaciones = $this->albaran->observaciones;
      $factura->provincia = $this->albaran->provincia;
      $factura->total = $this->albaran->total;
      $factura->totaliva = $this->albaran->totaliva;
      $factura->numero2 = $this->albaran->numero2;
      $factura->irpf = $this->albaran->irpf;
      $factura->totalirpf = $this->albaran->totalirpf;
      $factura->totalrecargo = $this->albaran->totalrecargo;
      $factura->porcomision = $this->albaran->porcomision;
      $factura->recfinanciero = $this->albaran->recfinanciero;
      
      /// asignamos la mejor fecha posible, pero dentro del ejercicio
      $eje0 = $this->ejercicio->get($factura->codejercicio);
      $factura->fecha = $eje0->get_best_fecha($factura->fecha);
      
      $regularizacion = new regularizacion_iva();
      
      if( !$eje0->abierto() )
      {
         $this->new_error_msg("El ejercicio está cerrado.");
      }
      else if( $regularizacion->get_fecha_inside($factura->fecha) )
      {
         $this->new_error_msg("El IVA de ese periodo ya ha sido regularizado.
            No se pueden añadir más facturas en esa fecha.");
      }
      else if( $factura->save() )
      {
         $continuar = TRUE;
         foreach($this->albaran->get_lineas() as $l)
         {
            $n = new linea_factura_cliente();
            $n->idalbaran = $l->idalbaran;
            $n->idfactura = $factura->idfactura;
            $n->cantidad = $l->cantidad;
            $n->codimpuesto = $l->codimpuesto;
            $n->descripcion = $l->descripcion;
            $n->dtolineal = $l->dtolineal;
            $n->dtopor = $l->dtopor;
            $n->irpf = $l->irpf;
            $n->iva = $l->iva;
            $n->pvpsindto = $l->pvpsindto;
            $n->pvptotal = $l->pvptotal;
            $n->pvpunitario = $l->pvpunitario;
            $n->recargo = $l->recargo;
            $n->referencia = $l->referencia;
            if( !$n->save() )
            {
               $continuar = FALSE;
               $this->new_error_msg("¡Imposible guardar la línea el artículo ".$n->referencia."! ");
               break;
            }
         }
         
         if($continuar)
         {
            $this->albaran->idfactura = $factura->idfactura;
            $this->albaran->ptefactura = FALSE;
            if( $this->albaran->save() )
            {
               $this->generar_asiento($factura);
            }
            else
            {
               $this->new_error_msg("¡Imposible vincular el ".FS_ALBARAN." con la nueva factura!");
               if( $factura->delete() )
               {
                  $this->new_error_msg("La factura se ha borrado.");
               }
               else
                  $this->new_error_msg("¡Imposible borrar la factura!");
            }
         }
         else
         {
            if( $factura->delete() )
            {
               $this->new_error_msg("La factura se ha borrado.");
            }
            else
               $this->new_error_msg("¡Imposible borrar la factura!");
         }
      }
      else
         $this->new_error_msg("¡Imposible guardar la factura!");
   }
   
   private function generar_asiento($factura)
   {
      if($this->empresa->contintegrada)
      {
         $asiento_factura = new asiento_factura();
         if( $asiento_factura->generar_asiento_venta($factura) )
         {
            $this->new_message("<a href='".$factura->url()."'>Factura</a> generada correctamente.");
         }
         
         foreach($asiento_factura->errors as $err)
         {
            $this->new_error_msg($err);
         }
         
         foreach($asiento_factura->messages as $msg)
         {
            $this->new_message($msg);
         }
      }
      else
      {
         $this->new_message("<a href='".$factura->url()."'>Factura</a> generada correctamente.");
      }
      
      $this->new_change('Factura Cliente '.$factura->codigo, $factura->url(), TRUE);
   }
   
   private function generar_pdf_simple($archivo = FALSE)
   {
      if( !$archivo )
      {
         /// desactivamos la plantilla HTML
         $this->template = FALSE;
      }
      
      $pdf_doc = new fs_pdf();
      $pdf_doc->pdf->addInfo('Title', FS_ALBARAN.' '. $this->albaran->codigo);
      $pdf_doc->pdf->addInfo('Subject', FS_ALBARAN.' de cliente ' . $this->albaran->codigo);
      $pdf_doc->pdf->addInfo('Author', $this->empresa->nombre);
      
      $lineas = $this->albaran->get_lineas();
      if($lineas)
      {
         $linea_actual = 0;
         $lppag = 42;
         $pagina = 1;
         
         /// imprimimos las páginas necesarias
         while( $linea_actual < count($lineas) )
         {
            /// salto de página
            if($linea_actual > 0)
               $pdf_doc->pdf->ezNewPage();
            
            /// ¿Añadimos el logo?
            if( file_exists('tmp/'.FS_TMP_NAME.'logo.png') )
            {
               $pdf_doc->pdf->ezImage('tmp/'.FS_TMP_NAME.'logo.png', 0, 200, 'none');
               $lppag -= 2; /// si metemos el logo, caben menos líneas
            }
            else
            {
               $pdf_doc->pdf->ezText("<b>".$this->empresa->nombre."</b>", 16, array('justification' => 'center'));
               $pdf_doc->pdf->ezText(FS_CIFNIF.": ".$this->empresa->cifnif, 8, array('justification' => 'center'));
               
               $direccion = $this->empresa->direccion;
               if($this->empresa->codpostal)
                  $direccion .= ' - ' . $this->empresa->codpostal;
               if($this->empresa->ciudad)
                  $direccion .= ' - ' . $this->empresa->ciudad;
               if($this->empresa->provincia)
                  $direccion .= ' (' . $this->empresa->provincia . ')';
               if($this->empresa->telefono)
                  $direccion .= ' - Teléfono: ' . $this->empresa->telefono;
               $pdf_doc->pdf->ezText($direccion, 9, array('justification' => 'center'));
            }
            
            /*
             * Esta es la tabla con los datos del cliente:
             * Albarán:             Fecha:
             * Cliente:             CIF/NIF:
             * Dirección:           Teléfonos:
             */
            $pdf_doc->new_table();
            $pdf_doc->add_table_row(
               array(
                   'campo1' => "<b>".ucfirst(FS_ALBARAN).":</b>",
                   'dato1' => $this->albaran->codigo,
                   'campo2' => "<b>Fecha:</b>",
                   'dato2' => $this->albaran->fecha
               )
            );
            $pdf_doc->add_table_row(
               array(
                   'campo1' => "<b>Cliente:</b>",
                   'dato1' => $this->albaran->nombrecliente,
                   'campo2' => "<b>".FS_CIFNIF.":</b>",
                   'dato2' => $this->albaran->cifnif
               )
            );
            $pdf_doc->add_table_row(
               array(
                   'campo1' => "<b>Dirección:</b>",
                   'dato1' => $this->albaran->direccion.' CP: '.$this->albaran->codpostal.' - '.$this->albaran->ciudad.' ('.$this->albaran->provincia.')',
                   'campo2' => "<b>Teléfonos:</b>",
                   'dato2' => $this->cliente_s->telefono1.'  '.$this->cliente_s->telefono2
               )
            );
            $pdf_doc->save_table(
               array(
                   'cols' => array(
                       'campo1' => array('justification' => 'right'),
                       'dato1' => array('justification' => 'left'),
                       'campo2' => array('justification' => 'right'),
                       'dato2' => array('justification' => 'left')
                   ),
                   'showLines' => 0,
                   'width' => 540,
                   'shaded' => 0
               )
            );
            $pdf_doc->pdf->ezText("\n", 10);
            
            
            /*
             * Creamos la tabla con las lineas del albarán:
             * 
             * Descripción    PVP   DTO   Cantidad    Importe
             */
            $pdf_doc->new_table();
            $pdf_doc->add_table_header(
               array(
                  'descripcion' => '<b>Descripción</b>',
                  'pvp' => '<b>PVP</b>',
                  'dto' => '<b>DTO</b>',
                  'cantidad' => '<b>Cantidad</b>',
                  'importe' => '<b>Importe</b>'
               )
            );
            $saltos = 0;
            $subtotal = 0;
            $impuestos = array();
            for($i = $linea_actual; (($linea_actual < ($lppag + $i)) AND ($linea_actual < count($lineas)));)
            {
               if( !isset($impuestos[$lineas[$linea_actual]->iva]) )
                  $impuestos[$lineas[$linea_actual]->iva] = $lineas[$linea_actual]->pvptotal * $lineas[$linea_actual]->iva / 100;
               else
                  $impuestos[$lineas[$linea_actual]->iva] += $lineas[$linea_actual]->pvptotal * $lineas[$linea_actual]->iva / 100;
               
               $fila = array(
                  'descripcion' => substr($lineas[$linea_actual]->descripcion, 0, 45),
                  'pvp' => $this->show_precio($lineas[$linea_actual]->pvpunitario, $this->albaran->coddivisa),
                  'dto' => $this->show_numero($lineas[$linea_actual]->dtopor, 0) . " %",
                  'cantidad' => $lineas[$linea_actual]->cantidad,
                  'importe' => $this->show_precio($lineas[$linea_actual]->pvptotal, $this->albaran->coddivisa)
               );
               
               if($lineas[$linea_actual]->referencia != '0')
                  $fila['descripcion'] = substr($lineas[$linea_actual]->referencia.' - '.$lineas[$linea_actual]->descripcion, 0, 40);
               
               $pdf_doc->add_table_row($fila);
               $saltos++;
               $linea_actual++;
            }
            $pdf_doc->save_table(
               array(
                   'fontSize' => 8,
                   'cols' => array(
                       'pvp' => array('justification' => 'right'),
                       'dto' => array('justification' => 'right'),
                       'cantidad' => array('justification' => 'right'),
                       'importe' => array('justification' => 'right')
                   ),
                   'width' => 540,
                   'shaded' => 0
               )
            );
            
            
            /*
             * Rellenamos el hueco que falta hasta donde debe aparecer la última tabla
             */
            if($this->albaran->observaciones == '')
            {
               $salto = '';
            }
            else
            {
               $salto = "\n<b>Observaciones</b>: " . $this->albaran->observaciones;
               $saltos += count( explode("\n", $this->albaran->observaciones) ) - 1;
            }
            
            if($saltos < $lppag)
            {
               for(;$saltos < $lppag; $saltos++)
                  $salto .= "\n";
               $pdf_doc->pdf->ezText($salto, 11);
            }
            else if($linea_actual >= $lineasfact)
               $pdf_doc->pdf->ezText($salto, 11);
            else
               $pdf_doc->pdf->ezText("\n", 11);
            
            
            /*
             * Rellenamos la última tabla de la página:
             * 
             * Página            Neto    IVA   Total
             */
            $pdf_doc->new_table();
            $titulo = array('pagina' => '<b>Página</b>', 'neto' => '<b>Neto</b>',);
            $fila = array(
                'pagina' => $pagina . '/' . ceil(count($lineas) / $lppag),
                'neto' => $this->show_precio($this->albaran->neto, $this->albaran->coddivisa),
            );
            $opciones = array(
                'cols' => array(
                    'neto' => array('justification' => 'right'),
                ),
                'showLines' => 4,
                'width' => 540
            );
            foreach($impuestos as $i => $value)
            {
               $titulo['iva'.$i] = '<b>IVA '.$i.'%</b>';
               $fila['iva'.$i] = $this->show_precio($value, $this->albaran->coddivisa);
               $opciones['cols']['iva'.$i] = array('justification' => 'right');
            }
            $titulo['liquido'] = '<b>Total</b>';
            $fila['liquido'] = $this->show_precio($this->albaran->total, $this->albaran->coddivisa);
            $opciones['cols']['liquido'] = array('justification' => 'right');
            $pdf_doc->add_table_header($titulo);
            $pdf_doc->add_table_row($fila);
            $pdf_doc->save_table($opciones);
            $pdf_doc->pdf->ezText("\n", 10);
            
            $pdf_doc->pdf->addText(10, 10, 8, $pdf_doc->center_text($this->empresa->pie_factura, 153), 0, 1.5);
            
            $pagina++;
         }
      }
      
      if($archivo)
      {
         if( !file_exists('tmp/'.FS_TMP_NAME.'enviar') )
            mkdir('tmp/'.FS_TMP_NAME.'enviar');
         
         $pdf_doc->save('tmp/'.FS_TMP_NAME.'enviar/'.$archivo);
      }
      else
         $pdf_doc->show();
   }
   
   private function generar_pdf_cuartilla()
   {
      /// desactivamos el motor de plantillas
      $this->template = FALSE;
      
      $pdf_doc = new fs_pdf();
      $pdf_doc->pdf->addInfo('Title', FS_ALBARAN.' '. $this->albaran->codigo);
      $pdf_doc->pdf->addInfo('Subject', FS_ALBARAN.' de cliente ' . $this->albaran->codigo);
      $pdf_doc->pdf->addInfo('Author', $this->empresa->nombre);
      
      $lineas = $this->albaran->get_lineas();
      if($lineas)
      {
         $linea_actual = 0;
         $lppag = 14;
         $pagina = 1;
         
         /// imprimimos las páginas necesarias
         while( $linea_actual < count($lineas) )
         {
            /// salto de página
            if($linea_actual > 0)
               $pdf_doc->pdf->ezNewPage();
            
            /// encabezado
            $texto = "<b>".ucfirst(FS_ALBARAN).":</b> ".$this->albaran->codigo."\n".
                    "<b>Fecha:</b> ".$this->albaran->fecha."\n".
                    "<b>SR. D:</b> ".$this->albaran->nombrecliente;
            $pdf_doc->pdf->ezText($texto, 12, array('justification' => 'right'));
            $pdf_doc->pdf->ezText("\n", 12);
            
            
            /// tabla principal
            $pdf_doc->new_table();
            $pdf_doc->add_table_header(
               array(
                   'unidades' => '<b>Ud.</b>',
                   'descripcion' => '<b>Descripción</b>',
                   'dto' => '<b>DTO.</b>',
                   'pvp' => '<b>P.U.</b>',
                   'importe' => '<b>Importe</b>'
               )
            );
            $saltos = 0;
            for($i = $linea_actual; (($linea_actual < ($lppag + $i)) AND ($linea_actual < count($lineas)));)
            {
               $pdf_doc->add_table_row(
                  Array(
                      'unidades' => $lineas[$linea_actual]->cantidad,
                      'descripcion' => $lineas[$linea_actual]->referencia.' - '.$lineas[$linea_actual]->descripcion,
                      'dto' => $this->show_numero($lineas[$linea_actual]->dtopor, 2).' %',
                      'pvp' => $this->show_precio($lineas[$linea_actual]->pvpunitario, $this->albaran->coddivisa),
                      'importe' => $this->show_precio($lineas[$linea_actual]->pvptotal, $this->albaran->coddivisa)
                  )
               );
               
               $linea_actual++;
               $saltos++;
            }
            $pdf_doc->save_table(
               array(
                   'fontSize' => 9,
                   'cols' => array(
                       'dto' => array('justification' => 'right'),
                       'pvp' => array('justification' => 'right'),
                       'importe' => array('justification' => 'right')
                   ),
                   'width' => 540,
                   'shadeCol' => array(0.9, 0.9, 0.9)
               )
            );
            
            /// Rellenamos el hueco que falta hasta donde debe aparecer la última tabla
            if($this->albaran->observaciones == '')
               $salto = '';
            else
            {
               $salto = "\n<b>Observaciones</b>: " . $this->albaran->observaciones;
               $saltos += count( explode("\n", $this->albaran->observaciones) ) - 1;
            }
            
            if($saltos < $lppag)
            {
               for(;$saltos < $lppag; $saltos++) { $salto .= "\n"; }
                  $pdf_doc->pdf->ezText($salto, 12);
            }
            else if( $linea_actual >= count($lineas) )
               $pdf_doc->pdf->ezText($salto, 12);
            else
               $pdf_doc->pdf->ezText("\n", 10);
            
            
            /// Escribimos los totales
            $opciones = array('justification' => 'right');
            $neto = '<b>Pag</b>: ' . $pagina . '/' . ceil(count($lineas) / $lppag);
            $neto .= '        <b>Neto</b>: ' . $this->show_precio($this->albaran->neto, $this->albaran->coddivisa);
            $neto .= '    <b>IVA</b>: ' . $this->show_precio($this->albaran->totaliva, $this->albaran->coddivisa);
            $neto .= '    <b>Total</b>: ' . $this->show_precio($this->albaran->total, $this->albaran->coddivisa);
            $pdf_doc->pdf->ezText($neto, 12, $opciones);
            
            $pagina++;
         }
      }
      
      $pdf_doc->show();
   }
   
   private function enviar_email()
   {
      $cliente = $this->cliente->get($this->albaran->codcliente);
      
      if( $this->empresa->can_send_mail() AND $cliente )
      {
         if( $_POST['email'] != $cliente->email )
         {
            $cliente->email = $_POST['email'];
            $cliente->save();
         }
         
         /// obtenemos la configuración extra del email
         $mailop = array(
             'mail_host' => 'smtp.gmail.com',
             'mail_port' => '465',
             'mail_user' => '',
             'mail_enc' => 'ssl'
         );
         $fsvar = new fs_var();
         $mailop = $fsvar->array_get($mailop, FALSE);
         
         $filename = 'albaran_'.$this->albaran->codigo.'.pdf';
         $this->generar_pdf_simple($filename);
         if( file_exists('tmp/'.FS_TMP_NAME.'enviar/'.$filename) )
         {
            $mail = new PHPMailer();
            $mail->IsSMTP();
            $mail->SMTPAuth = TRUE;
            $mail->SMTPSecure = $mailop['mail_enc'];
            $mail->Host = $mailop['mail_host'];
            $mail->Port = intval($mailop['mail_port']);
            
            if($mailop['mail_user'] != '')
               $mail->Username = $mailop['mail_user'];
            else
               $mail->Username = $this->empresa->email;
            
            $mail->Password = $this->empresa->email_password;
            $mail->From = $this->empresa->email;
            $mail->FromName = $this->user->nick;
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $this->empresa->nombre . ': Su '.FS_ALBARAN.' '.$this->albaran->codigo;
            $mail->AltBody = 'Buenos días, le adjunto su '.FS_ALBARAN.' '.$this->albaran->codigo.".\n".$this->empresa->email_firma;
            $mail->WordWrap = 50;
            $mail->MsgHTML( nl2br($_POST['mensaje']) );
            $mail->AddAttachment('tmp/'.FS_TMP_NAME.'enviar/'.$filename);
            $mail->AddAddress($_POST['email'], $cliente->nombrecomercial);
            $mail->IsHTML(TRUE);
            
            if( $mail->Send() )
               $this->new_message('Mensaje enviado correctamente.');
            else
               $this->new_error_msg("Error al enviar el email: " . $mail->ErrorInfo);
         }
         else
            $this->new_error_msg('Imposible generar el PDF.');
      }
   }
}
