<?php
/* $Revision: 1.7 $ */

$PageSecurity =11;
$title = "Process EDI Orders";

include ("includes/session.inc");
include ("includes/header.inc");
include ("includes/DateFunctions.inc");
include("includes/SQL_CommonFunctions.inc"); /\need for EDITransNo
include("includes/htmlMimeMail.php"); // need for sending email attachments
include("includes/DefineCartClass.php");

$CompanyRecord = ReadInCompanyRecord($db);

/*The logic outline is this ....

Make an array of the format of the ORDER message from the table EDI_ORDERS_Segs

Get the list of files in EDI_Incoming_Orders - work through each one as follows

Read in the flat file one line at a time

Compare the SegTag in the flat file with the expected SegTag from EDI_ORDERS_Segs

parse the data in the line of text from the flat file to enable the order to be created

Compile an html email to the customer service person based on the location
of the customer doing the ordering and where it would be best to pick the order from

Read the next line of the flat file ...

*/


/*Read in the EANCOM Order Segments for the current seg group from the segments table */


$sql = "SELECT ID, SegTag, MaxOccur, SegGroup FROM EDI_ORDERS_Segs";
$OrderSeg = DB_query($sql,$db);
$i=0;
$Seg = array();

while ($SegRow=DB_fetch_array($OrderSeg)){
	$Seg[$i] = array('SegTag'=>$SegRow['SegTag'], 'MaxOccur'=>$SegRow['MaxOccur'], 'SegGroup'=>$SegRow['SegGroup']);
	$i++;
}

$TotalNoOfSegments = $i-1;

/*get the list of files in the incoming orders directory - from config.php */
$dirhandle = opendir($_SERVER['DOCUMENT_ROOT'] . "/" . $rootpath . "/" . $EDI_Incoming_Orders);

 while (false !== ($OrderFile=readdir($dirhandle))){ /*there are files in the incoming orders dir */

	$TryNextFile = False;
	echo "<BR>$OrderFile";

	/*Counter that keeps track of the array pointer for the 1st seg in the current seg group */
	$FirstSegInGrp =0;
	$SegGroup =0;

	$fp = fopen($_SERVER['DOCUMENT_ROOT'] . "/$rootpath/$EDI_Incoming_Orders/$OrderFile","r");

	$SegID = 0;
	$SegCounter =0;
	$SegTag='';
	$LastSeg = 0;
	$FirstSegInGroup = 0;
	$EmailText =""; /*Text of email to send to customer service person */
	$CreateOrder = True; /*Assume create a sales order in the system for the message read */

	$Order = new cart;

	while ($LineText = fgets($fp) AND $TryNextFile != True){ /* get each line of the order file */

		$LineText = StripTrailingComma($LineText);
		echo "<BR>$LineText";

		if ($SegTag != substr($LineText,0,3)){
			$SegCounter=1;
			$SegTag = substr($LineText,0,3);
		} else {
			$SegCounter++;
			if ($SegCounter > $Seg[$SegID]['MaxOccur']){
				$EmailText = $EmailText . "\nThe EANCOM Standard only allows for " . $Seg[$SegID]['MaxOccur'] . " occurrences of the segment " . $Seg[$SegID]['SegTag'] . " this is the " . $SegCounter . " occurrence. <BR>The segment line read as follows:<BR>" . $LineText;
			}
		}

/* Go through segments in the order message array in sequence looking for matching SegTags

   */
		while ($SegTag != $Seg[$SegID]['SegTag'] AND $SegID < $TotalNoOfSegments) {

			$SegID++; /*Move to the next Seg in the order message */
			$LastSeg = $SegID; /*Remember the last segid moved to */

			echo "\nSegment Group = " . $Seg[$SegID]['SegGroup'] . " Max Occurences of Segment = " . $Seg[$SegID]['MaxOccur'] . " No occurrences so far = " . $SegCounter;

			if ($Seg[$SegID]['SegGroup'] != $SegGroup AND $Seg[$SegID]['MaxOccur'] > $SegCounter){ /*moved to a new seg group  but could be more segment groups*/
				$SegID = $FirstSegInGroup; /*Try going back to first seg in the group */
				if ($SegTag != $Seg[$SegID]['SegTag']){ /*still no match - must be into new seg group */
					$SegID = $LastSeg;
					$FirstSegInGroup = $SegID;
				} else {
					$SegGroup = $Seg[$SegID]['SegGroup'];
				}
			}
		}

		if ($SegTag != $Seg[$SegID]['SegTag']){

			$EmailText .= "\nERROR: Unable to identify segment tag " . $SegTag . " from the message line <BR>" . $LineText . "<BR><FONT COLOR=RED><B>This message processing has been aborted and seperate advice will be required from the customer to obtain details of the order<B></FONT>";

			$TryNextFile = True;
		}

		echo "<BR>The segment tag " . $SegTag . " is being processed";
		switch ($SegTag){
			case 'UNH':
				$UNH_elements = explode ('+',substr($LineText,4));
				$Order->Comments .= "Customer EDI Ref: " . $UNH_elements[0];
				$EmailText .= "\nEDI Message Ref " . $UNH_elements[0];
				if (substr($UNH_elements[1],0,6)!='ORDERS'){
					$EmailText .= "\nThis message is not an order";
					$TryNextFile = True;
				}

				break;
			case 'BGM':
				$BGM_elements = explode('+',substr($LineText,4));
				$BGM_C002 = explode(':',$BGM_elements[0]);
				switch ($BGM_C002[0]){
					case '220':
						$EmailText .= "\nThis message is a standard order";
						break;
					case '221':
						$EmailText .= "\nThis message is a blanket order";
						$Order->Comments .= "\n blanket order";
						break;
					case '224':
						$EmailText .= "\n\nThis order is URGENT</FONT>";
						$Order->Comments .= "\n URGENT ORDER";
						break;
					case '226':
						$EmailText .= "\nCall off order";
						$Order->Comments .= "\n Call Off Order";
						break;
					case '227':
						$EmailText .= "\nConsignment order";
						$Order->Comments .= "\n consigment order";
						break;
					case '22E':
						$EmailText .= "\nManufacturer raised order";
						$Order->Comments .= "\n Manufacturer raised order";
						break;
					case '258':
						$EmailText .= "\nStanding order";
						$Order->Comments .= "\n standing order";
						break;
					case '237':
						$EmailText .= "\nCross docking services order";
						$Order->Comments .= "\n Cross docking services order";
						break;
					case '400':
						$EmailText .= "\nExceptional Order";
						$Order->Comments .= "\n exceptional order";
						break;
					case '401':
						$EmailText .= "\nTrans-shipment order";
						$Order->Comments .= "\n Trans-shipment order";
						break;
					case '402':
						$EmailText .= "\nCross docking order";
						$Order->Comments .= "\n cross docking order";
						break;

				} /*end switch for type of order */
				if (isset($BGM_elements[1])){
					echo "<BR>echo BGM_elements[1] " .$BGM_elements[1];
					$BGM_C106 = explode(':',$BGM_elements[1]);
					$Order->CustRef = $BGM_C106[0];
					$EmailText .= "\nCustomer's order ref: " . $BGM_C106[0];
				}
				if (isset($BGM_elements[2])){
					echo "<BR>echo BGM_elements[2] " .$BGM_elements[2];
					$BGM_1225 = explode(':',$BGM_elements[2]);
					$MsgFunction = $BGM_1225[0];


					switch ($MsgFunction){
						case '5':
							$EmailText .= "\n\nREPLACEMENT order - MUST DELETE THE ORIGINAL ORDER MANUALLY";
							break;
						case '6':
							$EmailText .= "\nConfirmation of previously sent order";
							break;
						case '7':
							$EmailText .= "\n\nDUPLICATE order DELETE ORIGINAL ORDER MANUALLY";
							break;
						case '16':
							$CreateOrder = False; /*Dont create order in system */
							$EmailText .= "\n\nProposed order only no order created in web-erp";
							break;
						case '31':
							$CreateOrder = False; /*Dont create order in system */
							$EmailText .= "\nCOPY order only no order will be created in web-erp";
							break;
						case '42':
							$CreateOrder = False; /*Dont create order in system */
							$EmailText .= "\nConfirmation of order - not created in web-erp";
							break;
						case '46':
							$CreateOrder = False; /*Dont create order in system */
							$EmailText .= "\nProvisional order only- not created in web-erp";
							break;
					}

					if (isset($BGM_1225[1])){
						$ResponseCode = $BGM_1225[1];
						echo "<BR>Response Code: " . $ResponseCode;
						switch ($ResponseCode) {
							case 'AC':
								$EmailText .= "\nPlease acknowlege to customer with detail and changes made to the order";
								break;
							case 'AB':
								$EmailText .= "\nPlease acknowlege to customer the receipt of message";
								break;
							case 'AI':
								$EmailText .= "\nPlease acknowlege to customer any changes to the order";
								break;
							case 'NA':
								$EmailText .= "\nNo acknowlegement to customer is required";
								break;
						}
					}
				}
				break;
			case 'DTM':
				/*explode into an arrage all items delimited by the : - only after the + */
				$DTM_C507 = explode(':',substr($LineText,4));
				$LocalFormatDate = ConvertEDIDate($DTM_C507[1],$DTM_C507[2]);

				switch ($DTM_C507[0]){
					case '2': /*Delivery date */
					case '10': /*shipment date requested */
					case '11': /*dispatch date */
					case 'X14': /*Reguested delivery week commencing EAN code */
					case '64': /*Earliest delivery date */
					case '69': /*Promised delivery date */
						$Order->DeliveryDate = $LocalFormatDate;
						$EmailText .= "\nRequested delivery date " . $Order->DeliveryDate;
						break;
					case '15': /*promotion start date */
						$EmailText .= "\nPromotion start date " . $LocalFormatDate;
						break;
					case '37': /*ship not before */
						$EmailText .= "\nDo NOT ship before " . $LocalFormatDate;
						break;
					case '38': /*ship not later than */
					case '61': /*Cancel if not delivered by this date */
					case '63': /*Latest delivery date */
					case '393': /*Cancel if not shipped by this date */
						$EmailText .= "\nCancel order if not dispatched before " . $LocalFormatDate;
						break;
					case '137': /*Order date */
						$Order->Orig_OrderDate = $LocalFormatDate;
						$EmailText .= "\nOrder date " . $LocalFormatDate;
						break;
					case '171': /*A date relating to a RFF seg */
						$EmailText .= "\nReference dated " . $LocalFormatDate;
						if ($SegGroup == 1){
							$Order->Comments .= " dated " . $LocalFormatDate;
						}
						break;
					case '200': /*Pickup collection date/time */
						$EmailText .= "\n\nPickup date " . $LocalFormatDate;
						$Order->DeliveryDate = $LocalFormatDate;
						break;
					case '263': /*Invoicing period */
						$EmailText .= "\nInvoice period " . $LocalFormatDate;
						break;
					case '273': /*Validity period */
						$EmailText .= "\nValid period " . $LocalFormatDate;
						break;
					case '282': /*Confirmation date lead time */
						$EmailText .= "\nConfirmation of date lead time " . $LocalFormatDate;
						break;
				}
				break;
			case 'PAI':
				/*explode into an array all items delimited by the : - only after the + */
				$PAI_C534 = explode(':',substr($LineText,4));
				if ($PAI_C534[0]=='1'){
					$EmailText .= "\nPayment will be effected by a direct payment for this order.";
				} elseif($PAI_C534[0]=='OA'){
					$EmailText .= "\nThis order to be settled in accordance with the normal account trading terms";
				}
				if ($PAI_C534[1]=='20'){
					$EmailText .= "\nThe goods on this order - once delivered - will be held as security for the payment.";
				}
				if ($PAI_C534[2]=='42'){
					$EmailText .= "\nPayment will be effected to bank account";
				} elseif ($PAI_C534[2]=='60'){
					$EmailText .= "\nPayment will be effected by promissory note";
				} elseif ($PAI_C534[2]=='40'){
					$EmailText .= "\nPayment will be effected by a bill drawn by the creditor on the debtor";
				} elseif ($PAI_C534[2]=='10E'){
					$EmailText .= "\nPayment terms are defined in the Commerical Account Summary Section";
				}
				if (isset($PAI_C534[5])){
					if ($PAI_C534[5]=='2')
					$EmailText .= "\nPayment will be posted through the ordinary mail system";
				}
				break;
			case 'ALI':
				$ALI = explode('+',substr($LineText,4));
				if (strlen($ALI[0])>1){
					$EmailText .= "\nGoods of origin " . $ALI[0];
				}
				if (strlen($ALI[1])>1){
					$EmailText .= "\nDuty regime code " . $ALI[1];
				}
				switch ($ALI[2]){
					case '136':
						$EmailText .= "\nBuying group conditions apply";
						break;
					case '137':
						$EmailText .= "\n\nCancel the order if complete delivery is not possible on the requested date/time</FONT>";
						break;
					case '73E':
						$EmailText .= "\nDelivery subject to final authorisation";
						break;
					case '142':
						$EmailText .= "\nInvoiced but not replenished";
						break;
					case '143':
						$EmailText .= "\nReplenished but not invoiced";
						break;
					case '144':
						$EmailText .= "\nDeliver Full order";
						break;
				}
				break;
			case 'FTX':
				$FTX = explode('+',substr($LineText,4));
				/*agreed coded text is not catered for ... yet
				only free form text */
				if (strlen($FTX[3])>5){
					$FTX_C108=explode(':',$FTX[3]);
					$Order->Comments .= $FTX_C108[0] . " " . $FTX_C108[1] . " " . $FTX_C108[2] . " " . $FTX_C108[3] . " " . $FTX_C108[4];
					$EmailText .= "\n" . $FTX_C108[0] . " " . $FTX_C108[1] . " " . $FTX_C108[2] . " " . $FTX_C108[3] . " " . $FTX_C108[4] . " ";
				}
				break;
			case 'RFF':
				$RFF = explode(':',substr($LineText,4));
				switch ($RFF[0]){
					case 'AE':
						$MsgText = "\nAuthorisation for expense no " . $RFF[1];
						break;
					case 'BO':
						$MsgText =  "\nBlanket Order # " . $RFF[1];
						break;
					case 'CR':
						$MsgText =  "\nCustomer Ref # " . $RFF[1];
						break;
					case 'CT':
						$MsgText =  "\nContract # " . $RFF[1];
						break;
					case 'IP':
						$MsgText =  "\nImport Licence # " . $RFF[1];
						break;
					case 'ON':
						$MsgText =  "\nBuyer order # " . $RFF[1];
						break;
					case 'PD':
						$MsgText =  "\nPromo deal # " . $RFF[1];
						break;
					case 'PL':
						$MsgText =  "\nPrice List # " . $RFF[1];
						break;
					case 'UC':
						$MsgText =  "\nUltimate customer ref " . $RFF[1];
						break;
					case 'VN':
						$MsgText =  "\nSupplier Order # " . $RFF[1];
						break;
					case 'AKO':
						$MsgText =  "\nAction auth # " . $RFF[1];
						break;
					case 'ANJ':
						$MsgText =  "\nAuthorisation # " . $RFF[1];
						break;
				}
				if ($SegGroup == 1){
					$Order->Comments .= $MsgText;
				}
				$EmailText .= $MsgText;
				break;
			case 'NAD':
				$NAD = explode('+',substr($LineText,4));
				if (strlen($NAD[1]>3)){ /*EAN Number reference is used for party details */
					$NAD_C082 = explode(':', $NAD[1]);
					switch ($NAD[0]){
						case 'BY':
						case 'IV':
							/*Look up the EAN Code given $NAD[1] for the buyer */							/*NAD_C082[2] must = 9 too but that is the only option anyway?? */
							$InvoiceeResult = DB_query("SELECT DebtorNo FROM DebtorsMaster WHERE EDIReference='" . $NAD_C082[0] . "' AND EDIOrders=1",$db);
							if (DB_num_rows($InvoiceeResult)!=1){
								$EmailText .= "\nThe Buyer reference was specified as an EAN International Article Numbering Association code. Unfortunately, the field EDIReference of any of the customer's currently set up to receive EDI orders does not match with the code " . $NAD_C082[0] . " used in this message. So, that's the end of the road for this message ... ";
								$TryNextFile = True; /* Look for other EDI msgs */
								$CreateOrder = False; /*Dont create order in system */
							} else {
								$CustRow = DB_fetch_array($InvoiceeResult);
								$Order->DebtorNo = $CustRow['DebtorNo'];
							}
							break;
						case 'SU':
							/*Supplier party details. This should be our EAN IANA number if not the message is not for us!! */
							if ($NAD_C082[0]!= $EDIReference){
								/* $EDIReference is set in config.php as our EDIReference it should be our EAN International Article Numbering Association code */
								$EmailText .= "\nThe supplier reference was specified as an EAN International Article Numbering Association code. Unfortunately,the company EDIReference - $EDIReference does not match with the code " . $NAD_C082[0] . " used in this message. This implies that the EDI message if for some other supplier !! no further processing will be done.";
								$TryNextFile = True; /* Look for other EDI msgs */
								$CreateOrder = False; /*Dont create order in system */						}
							break;
					}
				}
				break;

		} /*end case  Seg Tag*/
	} /*end while get next line of message */
	/*Thats the end of the message or had to abort */
	if (strlen($EmailText)>10){
		/*Now send the email off to the appropriate person */
		$mail = new htmlMimeMail();
		$mail->setText($EmailText);
		$mail->setFrom($CompanyName . "<" . $CompanyRecord['Email'] . ">");

		if ($TryNextFile==True){ /*had to abort this message */
			/* send the email to the sysadmin  - get email address from users*/

			$Result = DB_query("SELECT RealName, Email FROM WWW_Users WHERE FullAccess=7 AND Email <>''",$db);
			if (DB_num_rows($Result)==0){ /*There are no sysadmins with email address specified */

				$Recipients = array("'phil' <phil@localhost>");

			} else { /*Make an array of the sysadmin recipients */
				$Recipients = array();
				$i=0;
				while ($SysAdminsRow=DB_fetch_array($Result)){
					$Recipients[$i] = "'" . $SysAdminsRow['RealName'] . "' <" . $SysAdminsRow['Email'] . ">";
					$i++;
				}
			}
			$TryNextFile=False; /*reset the abort to false before hit next file*/
			$mail->setSubject("EDI Order Message Error");
		} else {

			$mail->setSubject("EDI Order Message " . $Order->CustRef);
			$EDICustServPerson ="'phil' <phil@localhost>";
			$Recipients = array($EDICustServPerson);
		}



		$result = $mail->send($Recipients);

		echo $EmailText;
	} /* nothing in the email text to send - the message file is a complete dud - maybe directory */

	/*Now create the order from the $Order object  and commit to the DB*/




 } /*end of the loop around all the incoming order files in the incoming orders directory */


include ("includes/footer.inc");

function StripTrailingComma ($StringToStrip){

	if (strrpos($StringToStrip,"'")){
		Return substr($StringToStrip,0,strrpos($StringToStrip,"'"));
	} else {
		Return $StringToStrip;
	}
}

?>
