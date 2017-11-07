<?php
@session_start();
/*
Plugin Name: sn Gateway for MyCred
Plugin URI: http://
Description: sn Gateway for MyCred
Version: 1.0
*/

//error_reporting(E_ALL);
//ini_set('display_errors',1);

add_action('init', 'mycred_sn_init', 0);
function mycred_sn_init()
{
	add_filter( 'mycred_setup_gateways', 'add_myCRED_Payment_Gateway_sn' );
	function add_myCRED_Payment_Gateway_sn($gateways)
	{
		$gateways['sn'] = array(
			'title'    => 'sn',
			'callback' => array('myCRED_Payment_Gateway_sn')
		);
		return $gateways;
	}
	if (class_exists('myCRED_Payment_Gateway'))
	{
		class myCRED_Payment_Gateway_sn extends myCRED_Payment_Gateway
		{
			function __construct($gateway_prefs)
			{
				$types = mycred_get_types();
				$default_exchange = array();
				foreach ( $types as $type => $label )
					$default_exchange[ $type ] = 1;
				parent::__construct(array(
						'id'               => 'sn',
						'label'            => 'sn',
						'gateway_logo_url' => plugins_url( 'assets/images/sn.png', myCRED_PURCHASE ),
						'defaults'         => array(
							'sn_api'   => '',
							'exchange'      => $default_exchange
						)
					), $gateway_prefs);
			}
			function buy()
			{
				if ( ! isset( $this->prefs['sn_api'] ) || empty( $this->prefs['sn_api'] ) )
					wp_die( __( 'Please setup this gateway before attempting to make a purchase!', 'mycred' ) );
				$api = $this->prefs['sn_api'];
				$type = $this->get_point_type();
				$mycred = mycred( $type );
				$amount = $mycred->number( $_REQUEST['amount'] );
				$amount = abs( $amount );
				$cost = $this->get_cost( $amount, $type );
				$to = $this->get_to();
				$from = $this->current_user_id;
				if ( isset( $_REQUEST['revisit'] ) )
				{
					$this->transaction_id = strtoupper( $_REQUEST['revisit'] );
				}
				else
				{
					$post_id = $this->add_pending_payment( array( $to, $from, $amount, $cost, $this->prefs['currency'], $type ) );
					$this->transaction_id = get_the_title( $post_id );
				}
				try
				{
										// Security
					@session_start();
					$sec = uniqid();
					$md = md5($sec.'vm');
					// Security

					
					
					date_default_timezone_set("Asia/Tehran");


					$data_string = json_encode(array(
					'pin'=> $api,
					'price'=> $cost,
					'callback'=> $this->callback_url()."&custom=".$this->transaction_id."&md=".$md."&sec=".$sec ,
					'order_id'=> $this->transaction_id,
					'ip'=> $_SERVER['REMOTE_ADDR'],
					'callback_type'=>2
					));

					$ch = curl_init('https://developerapi.net/api/v1/request');
					curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch, CURLOPT_HTTPHEADER, array(
					'Content-Type: application/json',
					'Content-Length: ' . strlen($data_string))
					);
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
					curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, 20);
					$result = curl_exec($ch);
					curl_close($ch);


					$json = json_decode($result,true);					
					if ($json['result']&&$json['result'] ==1)
					{
						
									// Set Session
						$_SESSION[$sec] = [
							'price'=>(int)$cost ,
							'order_id'=>$this->transaction_id ,
							'au'=>$json['au'] ,
						];

						  echo ('<div style="display:none">'.$json['form'].'</div>Please wait ... <script language="javascript">document.payment.submit(); </script>');
					}
					else
					{
						echo 'Error:'. $json['result'];
					}
				}
				catch (SoapFault $ex)
				{
					echo  'Error: '.$ex->getMessage();
				}
				unset( $this );
				exit;
			}
			function process()
			{
									// Security
					@session_start();
					$sec=$_GET['sec'];
					$mdback = md5($sec.'vm');
					$mdurl=$_GET['md'];
					// Security
				if ( isset( $_REQUEST['custom'] ) && isset( $_GET['md'] ) && isset( $_GET['sec'] ) && $mdback == $mdurl )
				{
					$pending_post_id = sanitize_key( $_REQUEST['custom'] );
					$pending_payment = $this->get_pending_payment( $pending_post_id );
					if ( $pending_payment !== false )
					{
						$transData = $_SESSION[$sec];
                        $au=$transData['au']; //
					    $cost=$transData['price']; //
						
						$time = $_REQUEST['custom'];
						$new_call = array();
						$api = $this->prefs['sn_api'];
						try
						{
							date_default_timezone_set("Asia/Tehran");


								// CallBack
								$bank_return = $_POST + $_GET ;
								$data_string = json_encode(array (
								'pin' => $api,
								'price' => $cost,
								'order_id' => $time,
								'au' => $au,
								'bank_return' =>$bank_return,
								));

								$ch = curl_init('https://developerapi.net/api/v1/verify');
								curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
								curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
								curl_setopt($ch, CURLOPT_HTTPHEADER, array(
								'Content-Type: application/json',
								'Content-Length: ' . strlen($data_string))
								);
								curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
								curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT, 20);
								$result = curl_exec($ch);
								curl_close($ch);
								$json = json_decode($result,true);
							if (!empty($json['result']) AND $json['result']==1)
							{
								if ( $this->complete_payment( $pending_payment, $au ) )
								{
									$this->trash_pending_payment( $pending_post_id );
									header('location: '.$this->get_thankyou());
									exit;die;
								}
								else
								{
									$new_call[] = __( 'Failed to credit users account.', 'mycred' );
								}
							}
							else
							{
								$new_call[] = __( 'verify error('.$json['result'].').', 'mycred' );
							}
						}
						catch (SoapFault $ex)
						{
							$new_call[] = __( 'Error: '.$ex->getMessage(), 'mycred' );
						}
					}
					$this->log_call( $pending_post_id, $new_call );
					   	header('location: '.$this->get_cancelled( $_REQUEST['custom']));
					exit;die;
				}
			}


			function preferences()
			{
				?>
				<label class="subheader" for="<?php echo $this->field_id('sn_api'); ?>">API</label><ol><li><div class="h2"><input type="text" name="<?php echo $this->field_name('sn_api'); ?>" id="<?php echo $this->field_id('sn_api'); ?>" value="<?php echo $this->prefs['sn_api']; ?>" class="long" /></div></li></ol>
				<label class="subheader"><?php _e( 'Exchange Rates', 'mycred' ); ?></label><ol><?php $this->exchange_rate_setup(); ?></ol>
				<?php
			}
		}
	}
}

?>