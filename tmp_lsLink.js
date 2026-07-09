/**
 * file_name: lsLink.js
 * summary	: ë²ë ¹ ë§í¬ js
 * history	: 2019. 07. 18. [#16143] ìì¹ë²ê· ë³¸ë¬¸ ë§í¬ íì¸ìì²­
 *	 		  2019. 08. 13. [#16302] ì¡°ë¬¸ì ë³´ ì´ì ê³¼ ë¹êµ ë²ê·¸ ìì ìì²­ -> ajax ì¤ë³µì¤í ë°©ì§
 *			  2019. 11. 21. [#18165] ìì¹ë²ê·ìì íì ê·ì¹ì¼ë¡ ì¸ì©ë§í¬ê° ê±¸ë¦¬ëë¡ ê°ì 
 *			  2019. 11. 21. [#17838] ííë²ë ¹ ìë¹ì¤ ê°ì 
 *			  2019. 12. 12. [#18335] ì´ì ê³¼ë¹êµ ë²í¼ íì¸
 *			  2020. 08. 06. [#20636] ì°ê³ì ë³´ íììì ë§í¬ ì¤ë¥ íì¸
 *			  2020. 09. 03. [#20683] ìíì¼ ë²ë ¹ HTML ì¬ìì± ê°ì 
 *            2020. 09. 24. [#20889] ë¶ì¹ ì°ê³ ê¸°ë¥ ì¶ê° ìì²­
 *            2020. 10. 29. [#21280] ìì¹ë²ê· ë§í¬ ì¤ë¥ íì¸
 *            2020. 11. 26. [#21510] ìì¹ë²ê· ë§í¬ íì¸ ìì²­
 *            2020. 12. 03. [#21590] ìì¹ë²ê·ë§í¬ íì¸
 *            2021. 04. 01. [#24068] ì¡°ë¬¸ ì°íë²í¼ ë¬´íë¡ë© íì íì¸
 *            2021. 05. 20. [#24521] ìì¹ë²ê·ìì ì¡°ë¡ ë³í ë§í¬ê° ì ìì ì¼ë¡ ì´ë¦¬ì§ ìë ì¤ë¥ íì¸
 * 			  2021. 06. 24. [#24851] ë§í¬ íì ì¬ì´ì¦ ì¡°ì  ê°ë¥ íëë¡ ë³ê²½ ìì²­
 * 			  2021. 06. 30. [#24957] ìì¹ë²ê· ë³¸ë¬¸ ë§í¬ ìì 
 *			  2021. 07. 15. [#25174] ìì¹ë²ê· ë§í¬ ì¤ë¥ íì¸
 *			  2021. 12. 02. [#26269] ë²ë ¹ ë³¸ë¬¸ ì¤í¬ë¦½í¸ ì¤ë¥ íì¸
 *            2021. 12. 16. [#26348] ë²ë ¹ ë³¸ë¬¸ ì¡°ë¡ìì ì¡°ë¬¸ ë²í¼ ì¤ë¥
 *			  2022. 04. 14. [#28995] ê³µê³µê¸°ê´ ê·ì  ì°ê³ ì²ë¦¬ ìì²­
 *			  2022. 05. 19. [#29555] ììê·ì  ë§í¬ ì¡°ë¬¸ë¨ìë¡ ìì±í  ì ìëë¡ ê°ì 
 *			  2022. 07. 14. [#30269] íì ì¬íë¡ ë§í¬ ì¤ë¥ íì¸
 *			  2023. 02. 16. [#31795] ë§í¬ íì íµì¼ ìì²­
 *			  2023. 03. 02. [#32094] ìì¹ë²ê·ìì íì ê·ì¹ì¼ë¡ ë§í¬ ì¤ë¥ íì¸
 *			  2023. 03. 02. [#31795] ë§í¬ íì íµì¼ ìì²­
 *			  2023. 04. 24. [#32591] ìì íì ê·ì¹/ê·ì  ë§í¬íìíë©´ê³¼ ë³¸ë¬¸íìíë©´ì ìë¨ë²í¼ì´ ë¤ë¥´ê² ë¸ì¶ëë íì íì¸ìì²­
 * 			  2023. 07. 13. [#32939] ìì¹ë²ê· ë§í¬ ì¶ì¶ì ë°ë¥¸ íì íë©´ ìì± ë¡ì§ ë³ê²½
 *			  2023. 09. 21. [#33137] ì¡°ë¡-ì¡°ë¡ìíê·ì¹ ììê´ë¦¬ ê¸°ë¥ ê°ì ìì²­
 *			  2024. 03. 28. [#34507] ì°ê³ì ë³´ íìì°½ ë´ ë§í¬ ì´ë¦¬ì§ ìë ì¤ë¥ ìì 
 *			  2024. 03. 28. [#34523] ìì¹ë²ê· ì¡°ë¬¸ë§í¬ íì´ì§ìì ì¸ìì ì¤ë¥ ìì 
 *            2024. 08. 29. [#35232] ê·ì ìì´ì½ íì ë§ ìë¹ì¤ ìì²­
 *            2025. 02. 06. [#35850] ìì¤ì½ë ë³´ìì½ì  ì§ë¨ ì²ë¦¬ : ì ê±°ëì§ ìê³  ë¨ì ëë²ê·¸ ì½ë
 *			  2026. 04. 03. [#40177] ë²ë ¹ íì íì´ì§ íìë²ë ¹ ë§í¬ ì´ë¦¬ì§ ìë íì íì¸
*/

var lnkTitle = "";
var $openPopWidth = "";
var $openPopHeight = "";
var LsLinkLayer = function(){
	var lsLinkLayer;
	return {
		showLsLinkLayer : function(size, title){
			if(size==0){
					if(lsLinkLayer){
						lsLinkLayer.dialog('close');
					}
					
					lsLinkLayer = $('#lsLinkLayer').dialog({
		            	autoOpen : false
		               ,width: 800
		               ,height: 300
		               ,modal: false
					   ,title: title
					   ,resizable: false
					   ,position: {
							// ë´ ê°ì²´ ìì¹
							my : 'center',
							// ì°¸ì¡°í  ê°ì²´ ìì¹
							at : 'center',
							// ì°¸ì¡°í  ê°ì²´ ì§ì 
							of : 'body'
						}
		            });
					
				lsLinkLayer.dialog("open");
				
				lsLinkLayer.show();
			}
			
		}
		,hiddenLsLinkLayer : function(){
			if(lsLinkLayer){
				lsLinkLayer.dialog("close");
			}
		}
		,returnLsLinkLayer : function(){
			return lsLinkLayer;
		}
	};
}();

/**
 * <pre>
 *  ì¡°ë¬¸ íìì°½ ë«ì ë, ì¡°ì ë ì°½í¬ê¸° ì ì¥
 * </pre>
 * @param widthTest
 * @param heightTest
 */
function setLsPopSize(popWidth, popHeight) {
	$openPopWidth = popWidth;
	$openPopHeight = popHeight;
}
/**
 * <pre>
 * 	ë²ë ¹ë§í¬ íì - BATCH(BatchCreateLsHtml)ë¥¼ íµí´ ìì±ë HTML ë§í¬ì ëí í¨ì
 *  <shcho> ancYnChk íë¼ë¯¸í° ì¶ê°
 * </pre>
 * @author brKim
 * @since 2017. 10. 11.
 * @param lsJoLnkSeq
 * @param docType
 */
function fncLsLawPop(lsJoLnkSeq, docType, ancYnChk) {
	var url = "lsLinkCommonInfo.do?lsJoLnkSeq="+lsJoLnkSeq;
	
	if (docType.substring(0,1) == "B") { //-- ë³í/ìì
		url = "lsLawLinkInfo.do?lsJoLnkSeq="+lsJoLnkSeq;
		openPop(url);
	} else if (docType == 'AR') { // ë¶ì¹
		url = "lsLawLinkInfo.do?lsJoLnkSeq="+lsJoLnkSeq;
		var popupX = (window.screen.width / 2) - (800 / 2);
		var popupY = (window.screen.height / 2) - (270 / 2);
		var win = window.open(url, 'ë¶ì¹ì ë³´', 'scrollbars=no,toolbar=no,resizable=no,status=no,menubar=no,width=800px,height=266px,left=' + popupX + ',top=' + popupY);
	}else if (docType == 'JO') { // ì¡°ë¬¸
		if($openPopWidth == null || $openPopWidth == "" && $openPopHeight == null || $openPopHeight == "") {
			url +="&chrClsCd=" + getValue("lsBdyChrCls") +"&ancYnChk=" + ancYnChk;
			var win = window.open(url, 'ì¡°ë¬¸ì ë³´', 'scrollbars=yes,toolbar=no,resizable=yes,status=no,menubar=no,width=798px,height=681px');
		}else{
			url +="&chrClsCd=" + getValue("lsBdyChrCls") +"&ancYnChk=" + ancYnChk;
			var popupX = (window.screen.width / 2) - ($openPopWidth / 2);
			var popupY = (window.screen.height / 2) - ($openPopHeight / 2);
			var win = window.open(url, 'ì¡°ë¬¸ì ë³´', 'scrollbars=yes,toolbar=no,resizable=yes,status=no,menubar=no,width=' + $openPopWidth + ',height=' + $openPopHeight);
		}
	}else if (docType == 'ALLJO' || docType == 'XX') { // ë²ë ¹
		url +="&chrClsCd=" + getValue("lsBdyChrCls") +"&ancYnChk=" + ancYnChk;
		openPop(url,1000);
	}
}
/**
 * <pre>
 * 	ë²ë ¹ë§í¬ íì (ìëë§í¬ë fncLsPttnLinkPopì¼ë¡ ì´ë)
 * </pre>
 * @author brKim
 * @since 2017. 10. 11.
 * @param lsNmPara
 * @param docType
 * @param txtPara
 * @param lsIds
 * @param isLawNm
 * @param joEfYd
 * @param lsType
 * @param linkLawNm
 * @param linkStr
 * @param joSeq
 * @param linkJoNo
 * @param chkGubun
 */
function fncLawPop(lsNmPara, docType, txtPara, lsIds, isLawNm, joEfYd, lsType, linkLawNm, linkStr, joSeq, linkJoNo, chkGubun) {
	
	linkParamDel();
 	var lsiSeq = getValue("lsiSeq");
	var tempLsNm = lsNmPara.replaceAll("<strong>","");
	tempLsNm = tempLsNm.replaceAll("</strong>",""); // a1 ì¡°ë¬¸ë§í¬ êµ¬ë¶ì 
	tempLsNm = tempLsNm.replace("(êµ¬)",""); // (êµ¬)wì ê±°
	
	// ë²ë ¹ ì°ê³ ê´ë ¨. ifì¡°ê±´ ì¶ê°. ìë ì¡°ê±´ì else if ë¡  2014.06.11
	// docType ì´ "LO", "LR", "OR"ì¼ ëë§ íìì°½ì´ ë¨ëë¡.
	// 2014.09.25 linkStr ì 'ëíµë ¹ë ¹', 'ë¶ë ¹'ì´ ë¤ì´ê° ëë ì´ìª½ì íëë¡.
	
	if(!linkStr) {
		linkStr = "";
	}
	
	if ((linkStr.indexOf("ëíµë ¹ë ¹") > -1 || linkStr.indexOf("ë¶ë ¹") > -1) && (docType == "")) {
		docType = "JO";  // í¹ì  ë²ë ¹, ì¡°ë¬¸ì´ ë§í¬ëì´ ììë
	}

	if (docType == "LO" || docType == "LR" || docType == "OR") {
			
		//linkê° enable íì§ ììëë íìì°½ ë¨ì§ ìëë¡ ifì  ì¶ê° 
		//2014.06.24
		//2014.08.08
		var isEnableLink = true;
		if (!isEnableLink) {
			return;
		} else {
		
			var lsId = lsIds;
			var lsThdCmpCls = docType;
			var joNo = txtPara;
			var url = "lumLsLinkPop.do?" + "lsId=" + lsId + "&lsThdCmpCls=" + lsThdCmpCls
						+ "&joNo=" + joNo + "&linkText=" + linkStr;
	
			var size = "width=798, height=681, status=no, toolbar=no, resizable=no, scrollbars=no, menubar=no";
			
			// íìì°½ ì¤ë³µ ì´ë¦¼ ë°©ì§  2014.06.25
			var popObj = window.open(url, 'lsLinkPop', size);
			
			openLinkPop(popObj);
			
			return;
			
		}
	} else if (docType.substring(0,1) == "B") { // ë³íìì
		
	    var bylNO = "";
	    var bylBrNo = "";
	    
	    if (txtPara != "") {
	    	 try {
		    	bylNo = txtPara.substring(0,4);
		    	bylBrNo = txtPara.substring(4);
		  	} catch(e){}
	    }
	   
	    if (typeof lsiSeq == null) {
	    	lsiSeq = "";
	    }
	    
	    try {
	    	if (tempLsNm != lsNmPara) {
	    		lsiSeq = "";
	    	}
	    } catch(e){}
	        
	    if (tempLsNm == "ì") {
	    	try {
				var lsNmFull = getValue("lsNmTrim");
				
				if (lsNmFull != null) {
					if (lsNmFull.indexOf("ìíê·ì¹") > -1) {
						lsNmFull = lsNmFull.substring(0,lsNmFull.indexOf("ìíê·ì¹"));
						tempLsNm = lsNmFull + "ìíë ¹";
					}
				}
	    	} catch(e){}
	    }
	    
	    if (linkLawNm == "Ordin") {
	    	
	    	 var ordinSeq = getValue("ordinSeq");
	    	 var bylClsNm = "";
	    	 
	    	 if (docType == "BE") {
	    	 	bylClsNm = "ë³í";
	    	 } else if (docType == "BF") {
	    	 	bylClsNm = "ìì";
	    	 } else if (docType == "BG") {
	    		bylClsNm = "ë³ì§"; 
	    	 } else if(docType == "BH"){
	    		bylClsNm = "ë³ë"; 
	    	 } else {
	    	 	bylClsNm = "";
	    	 }
	    	 
	    	 if(ordinSeq == "") {
	    		 ordinSeq="0"
	    	 }
	    	 
	    	 openPop("ordinBylInfoPLinkR.do?ordinSeq=" + ordinSeq + "&ordinNm=" + encodeURIComponent(tempLsNm)
						+ "&bylNo=" + bylNo + "&bylBrNo=" + bylBrNo + "&bylClsNm="+ encodeURIComponent(bylClsNm)
						+ "&bylEfYd=" + joEfYd + "&ordinId=" + lsIds);
	    } else if(linkLawNm == "Admrul") {
	    	
	    	 if (docType == "BE") {
	    	 	bylClsNm = "ë³í";
	    	 } else if (docType == "BF") {
	    	 	bylClsNm = "ìì";
	    	 } else if (docType == "BG") {
	    		bylClsNm = "ë³ì§"; 
	    	 } else {
	    	 	bylClsNm = "";
	    	 }
	    	 
	    	 openPop("admRulBylInfoPLinkR.do?admRulNm=" + encodeURIComponent(tempLsNm)
						+ "&bylNo=" + bylNo + "&bylBrNo=" + bylBrNo + "&bylClsNm="+ encodeURIComponent(bylClsNm)
						+ "&bylEfYd=" + joEfYd);
	    } else {
		    // lsiSeq long Type
		    if (lsiSeq == "") {
		    	lsiSeq="0"
		    }
			openPop("lsBylInfoPLinkR.do?lsiSeq=" + lsiSeq + "&lsNm=" + encodeURIComponent(tempLsNm)
						+ "&bylNo=" + bylNo + "&bylBrNo=" + bylBrNo
						+ "&bylCls="+docType + "&bylEfYd=" + joEfYd + "&bylEfYdYn=Y");
		}
	    
	} else if (docType == "TL") {
		url = "trtyInfoP.do?trtyNm=" + encodeURIComponent(tempLsNm) + "&chrClsCd=010202&mode=4&lnkYn=Y"
		openPop(url, 1000);
	} else {
		
		// ë²ë ¹ , ì¡°ë¬¸
		if (txtPara != "") {
			
			if (joEfYd == '') {
				linkJoNo = '';
			}
			
			if ("ë²" == lsNmPara && chkGubun == "chkOrdin" ) {
				fSlimUpdateByOrdinJoConLawAjax("lsJoLayer", "ordinLsJoListR.do", "ordinSeq=" + getValue("ordinSeq"),
						tempLsNm, txtPara, docType, lsIds, linkLawNm, linkStr, joEfYd, linkJoNo, chkGubun);
			} else {
				//tempLsNm = encodeURIComponent(tempLsNm);
				joInfoShow(tempLsNm, txtPara, docType, lsIds, linkLawNm, linkStr, joEfYd, linkJoNo, chkGubun);		
			}
			
		} else {
			
			var url = "";
			
			if (linkLawNm == "Ordin") { // ìì¹ë²ê·
				url = "ordinLinkProc.do?" + "ordinNm=" + encodeURIComponent(tempLsNm) 
					+ "&chrClsCd=" + getValue("lsBdyChrCls") + "&mode=20";
				
				if ((lsIds.indexOf("detc") < 0) && (lsIds.indexOf("expc") < 0) && (lsIds.indexOf("decc") < 0)
						&& (lsIds.indexOf("ftc") < 0) && (lsIds.indexOf("acr") < 0) && (lsIds.indexOf("ppc") < 0)) {
					url = url + "&ordinId=" + lsIds;
				}
				
			} else if (linkLawNm == "Admrul") {	// íì ê·ì¹
				url = "admRulLinkProc.do?" + "admRulNm=" + encodeURIComponent(tempLsNm)
					+ "&chrClsCd=" + getValue("lsBdyChrCls") + "&mode=20";
			} else { // ë²ë ¹
				url = "lsLinkProc.do?" + "lsNm=" + encodeURIComponent(tempLsNm) 
					+ "&joLnkStr=" + encodeURIComponent(linkStr) + "&chrClsCd=" + getValue("lsBdyChrCls");
				
				if ((lsIds.indexOf("detc") > -1) || (lsIds.indexOf("expc") > -1) || (lsIds.indexOf("decc") > -1)) {
					var efYd = lsIds.substring(4, lsIds.length);
					url = url + "&efYd=" + efYd + "&mode=21";
				} else {
					url = url + "&mode=20";
				}
			}
			
			if (el('ancYd') != null && el('ancYd').value != "") {
				if (lsiSeq == null) {
					openPop(url + "&ancYd=" + el('ancYd').value, 1000);
				} else {
					openPop(url,1000);
				}
			} else if (typeof ancYd != 'undefined' && ancYd != "") {
				openPop(url + "&ancYd=" + ancYd, 1000);
			} else {
				openPop(url, 1000);
			}
		}
	}
}

// ë²ë ¹ lsi_seq ê° ê°ì ¸ì¤ê¸°
function getValue(idName){
	try{
		return el(idName).value;	
	}catch(e){
		return "";
	}
}

var linkParam = {lsiSeq : ""
				 ,ancYd : ""
				 ,lsClsCd : ""
				 ,lsNm : ""
				 ,lsId : ""
				 ,chrClsCd : ""
				 ,joNo : ""
			     ,endJoNo : ""
				 ,efYd : ""
				 ,joEfYd : ""
				 ,mode : ""
				 ,ordinSeq : ""
				 ,ordinNm : ""
				 ,ordinId : ""
				 ,joLnkStr : ""
				 ,lnkJoNo : ""
			     ,lnkGubun : ""
				 };

function linkParamDel(){
	linkParam.lsiSeq = "";
	linkParam.ancYd = "";
	linkParam.lsClsCd = "";
	linkParam.lsNm = "";
	linkParam.lsId = "";
	linkParam.chrClsCd = "";
	linkParam.joNo = "";
	linkParam.mode = "";
	linkParam.ordinSeq = "";
	linkParam.ordinNm = "";
	linkParam.ordinId = "";

}

/**
 * <pre>
 * 	ë²ë ¹ë§í¬ ì¡°ë¬¸ íì
 * </pre>
 * @author brKim
 * @since 2017. 10. 11.
 * @param lsNmPara
 * @param joNo
 * @param docType
 * @param lsIds
 * @param linkLawNm
 * @param linkStr
 * @param joEfYd
 * @param linkJoNo
 * @param chkGubun
 */
function joInfoShow(lsNmPara,joNo,docType, lsIds, linkLawNm,linkStr,joEfYd,linkJoNo, chkGubun) {
	
	// 2012.02.15 íë²ì¼ê²½ì° ììì¶ê°.
	if (lsNmPara == 'íë²') {
		lsNmPara = 'ëíë¯¼êµ­íë²';
	}
	
	var lsiSeq  = getValue("lsiSeq");
	var ancYd   = getValue("ancYd");
	var lsClsCd = getValue("lsClsCd");
	var lsNm    = getValue("lsNm");
	var lsId    = getValue("lsId");
	
	linkParam.chrClsCd = getValue("lsBdyChrCls");
	linkParam.joLnkStr = linkStr;
	linkParam.lnkJoNo = linkJoNo;
	
	if (linkStr == undefined) { 
		linkStr = '';
	}
	if (joEfYd == undefined) { 
		joEfYd = '';
	}
	if (linkJoNo == undefined) { 
		linkJoNo = '';
	}
	if (linkLawNm == undefined) { 
		linkLawNm = '';
	}
	
	// ìì¹ë²ê· ì²ë¦¬
	if (linkLawNm != "Ls"
	   && linkLawNm != "Admrul"
	   && lsNmPara.indexOf("ëíµë ¹ë ¹") < 0 && lsNmPara.indexOf("ë¶ë ¹") < 0 
	   && lsNmPara.indexOf("ì´ë¦¬ë ¹") < 0 && lsNmPara.indexOf("ëë²ìê·ì¹") < 0
	   && lsNmPara.indexOf("êµ­íê·ì¹") < 0 && lsNmPara.indexOf("íë²ì¬íìê·ì¹") < 0
	   && lsNmPara.indexOf("ì¤ìì ê±°ê´ë¦¬ììíê·ì¹") < 0 && lsNmPara.indexOf("ê°ì¬ìê·ì¹") < 0
	   && lsIds.indexOf("prec") < 0 && lsIds.indexOf("detc") < 0 && lsIds.indexOf("expc") < 0 && lsIds.indexOf("decc") < 0	   
	   && lsIds.indexOf("ftc") < 0 && lsIds.indexOf("acr") < 0 && lsIds.indexOf("ppc") < 0
	   && chkGubun == "chkOrdin") {
		
		lsiSeq  = getValue("ordinSeq");
	    lsNm    = getValue("ordinNm");
	    lsId    = getValue("ordinId");
	    
		if (lsNmPara == "ì¡°ë¡") {
		    linkLawNm = "Ordin";
		    linkParam.ordinNm = lsNm;
		    linkParam.ordinId = lsId;
		    linkParam.ordinSeq = lsiSeq;
		} else if (lsNmPara.indexOf("ì¡°ë¡") > 0) {
			lsNm = lsNmPara;
			lsNmPara = "ì¡°ë¡";
			linkParam.ordinNm = lsNm;
		    linkParam.ordinId = lsId;
		    linkParam.ordinSeq = lsiSeq;
		} else if (lsNmPara == "ê·ì ") {
		    linkLawNm = "Ordin";
		    linkParam.ordinNm = lsNm;
		    linkParam.ordinId = lsId;
		    linkParam.ordinSeq = lsiSeq;
		} else if (lsNmPara.indexOf("ê·ì ") > 0) {
			lsNm = lsNmPara;
			lsNmPara = "ê·ì ";
			linkParam.ordinNm = lsNm;
			linkParam.ordinId = lsId;
			linkParam.ordinSeq = lsiSeq;
		} else if (lsNmPara == "ê·ì¹") {
		    linkLawNm = "Ordin";
		    linkParam.ordinNm = lsNm;
		    linkParam.ordinId = lsId;
		    linkParam.ordinSeq = lsiSeq;
		} else if (lsNmPara.indexOf("ê·ì¹") > 0) {
			lsNm = lsNmPara;
			lsNmPara = "ê·ì¹";
			linkParam.ordinNm = lsNm;
			linkParam.ordinId = lsId;
			linkParam.ordinSeq = lsiSeq;
		} else {
			lsNm = lsNmPara;
			linkParam.ordinNm = lsNm;
			linkParam.ordinId = lsId;
			linkParam.ordinSeq = lsiSeq;
		}
	}
	
	if (lsIds.indexOf("prec") > -1) {
		lsNm = getValue("precNm");
		var precYd = getValue("precYd");
		if (lsIds == "prec") {
			lsIds = lsIds+precYd;
		}
	}
	if (lsIds.indexOf("detc") > -1) {
		lsNm    = getValue("detcNm");
	}
	if (lsIds.indexOf("expc") > -1) {
		lsNm    = getValue("expcNm");
	}

	if (lsIds.indexOf("decc") > -1) {
		lsNm    = getValue("deccNm");
	}
	
	if (lsIds.indexOf("ftc") > -1) {
		lsNm    = getValue("ftcNm");
	}
	
	if (lsIds.indexOf("acr") > -1) {
		lsNm    = getValue("acrNm");
	}
	
	if (lsIds.indexOf("ppc") > -1) {
		lsNm    = getValue("ppcNm");
	}
	
	if (lsId == "" && lsIds != "") {
		lsId = lsIds;
	}

	if (lsNm != "") {
		if (lsNmPara == lsNm) {
			if(linkLawNm == "Admrul"){
				linkParam.admRulNm = lsNmPara;
				linkParam.mode = 100;
			} else {
			linkParam.lsNm = lsNm;
			linkParam.joEfYd = joEfYd;
			linkParam.mode = 2;
			}
			linkParam.lsId = lsId;
			linkParam.joNo = joNo;
		} else if (lsNmPara == "ë²") {
			lsNm = makeLsLNm(lsClsCd);
			if (lsNm != getValue("lsNmTrim")) {
				linkParam.joNo = joNo;
				linkParam.joEfYd = joEfYd;
				linkParam.mode = 3;
				linkParam.lsNm = lsNm;
				linkParam.ancYd = ancYd;
				linkParam.lsClsCd = lsClsCd + "L";
				linkParam.lsId = lsId;
				logger.def("ë² 1",1);
			} else {
				linkParam.joNo = joNo;
				linkParam.joEfYd = joEfYd;
				linkParam.mode = 3;
				linkParam.lsNm = lsNm;
				linkParam.ancYd = ancYd;
				linkParam.lsClsCd = lsClsCd + "L";
				linkParam.lsId = lsId;
				linkParam.lsiSeq = lsiSeq;
				logger.def("ë² 2",1);
			}	
		} else if (lsNmPara == "ì¡°ë¡") {
			if (lsNm != getValue("lsNmTrim")) {
				linkParam.joNo = joNo;
				linkParam.joEfYd = joEfYd;
				linkParam.mode = 4;
				linkParam.ordinNm = lsNm;
				linkParam.ancYd = ancYd;
				linkParam.ordinId = lsId;
				logger.def("ì¡°ë¡ 1",1);
			} else {
				linkParam.joNo = joNo;
				linkParam.joEfYd = joEfYd;
				linkParam.mode = 4;
				linkParam.ordinNm = lsNm;
				linkParam.ancYd = ancYd;
				linkParam.ordinId = lsId;
				linkParam.ordinSeq = lsiSeq;
				logger.def("ì¡°ë¡ 2",1);
			}	
		} else if (lsNmPara == "ê·ì ") {
			if (lsNm != getValue("lsNmTrim")) {
				linkParam.joNo = joNo;
				linkParam.joEfYd = joEfYd;
				linkParam.mode = 4;
				linkParam.ordinNm = lsNm;
				linkParam.ancYd = ancYd;
				linkParam.ordinId = lsId;
				logger.def("ê·ì  1",1);
			} else {
				linkParam.joNo = joNo;
				linkParam.joEfYd = joEfYd;
				linkParam.mode = 4;
				linkParam.ordinNm = lsNm;
				linkParam.ancYd = ancYd;
				linkParam.ordinId = lsId;
				linkParam.ordinSeq = lsiSeq;
				logger.def("ê·ì  2",1);
			}	
		} else if (lsNmPara == "ê·ì¹") {
			if (lsNm != getValue("lsNmTrim")) {
				linkParam.joNo = joNo;
				linkParam.joEfYd = joEfYd;
				linkParam.mode = 4;
				linkParam.ordinNm = lsNm;
				linkParam.ancYd = ancYd;
				linkParam.ordinId = lsId;
				logger.def("ê·ì¹ 1",1);
			} else {
				linkParam.joNo = joNo;
				linkParam.joEfYd = joEfYd;
				linkParam.mode = 4;
				linkParam.ordinNm = lsNm;
				linkParam.ancYd = ancYd;
				linkParam.ordinId = lsId;
				linkParam.ordinSeq = lsiSeq;
				logger.def("ê·ì¹ 2",1);
			}	
		} else if (lsNmPara == "ì") {
			lsNm = makeLsONm(lsClsCd);
			if (lsNm != getValue("lsNmTrim")) {
				linkParam.joNo = joNo;
				linkParam.joEfYd = joEfYd;
				linkParam.mode = 3;
				linkParam.lsNm = lsNm;
				linkParam.ancYd = ancYd;
				linkParam.lsClsCd = lsClsCd + "O";
				linkParam.lsId = lsId;
				logger.def("ì 1",1);
			} else {
				linkParam.joNo = joNo;
				linkParam.joEfYd = joEfYd;
				linkParam.mode = 3;
				linkParam.ancYd = ancYd;
				linkParam.lsClsCd = lsClsCd + "O";
				linkParam.lsId = lsId;
				linkParam.lsiSeq = lsiSeq;
				logger.def("ì 2",1);
			}
		} else if ((lsNmPara.indexOf("ëíµë ¹ë ¹") >-1 || lsNmPara.indexOf("ëë²ìê·ì¹") >-1
				|| lsNmPara.indexOf("êµ­íê·ì¹")>-1 || lsNmPara.indexOf("íë²ì¬íìê·ì¹")>-1
				|| lsNmPara.indexOf("ì¤ìì ê±°ê´ë¦¬ììíê·ì¹")>-1 || lsNmPara.indexOf("ê°ì¬ìê·ì¹")>-1) && !linkStr) {
			if (lsId == "" && lsIds != "") {
				lsId = lsIds;
			}
			linkParam.joNo = joNo;
			linkParam.joEfYd = joEfYd;
			linkParam.mode = 5;
			linkParam.ancYd = ancYd;
			linkParam.lsClsCd = lsClsCd + "O";
			linkParam.lsId = lsId;
			linkParam.lsiSeq = lsiSeq;
			logger.def("ëíµë ¹ë ¹",1);
		} else if (lsNmPara.indexOf("ë¶ë ¹") > -1 || lsNmPara.indexOf("ì´ë¦¬ë ¹") > -1) {
			if (lsId == "" && lsIds != "") {
				lsId = lsIds;
			}
			linkParam.joNo = joNo;
			linkParam.joEfYd = joEfYd;
			linkParam.mode = 5;
			linkParam.ancYd = ancYd;
			linkParam.lsClsCd = lsClsCd + "R";
			linkParam.lsId = lsId;
			linkParam.lsiSeq = lsiSeq;
			linkParam.lsNm = lsNmPara; // íë¼ë¯¸í°ëªì ì ì¡
			logger.def("ë¶ë ¹",1);
		} else if (lsNmPara == "" && lsiSeq != "") {
			linkParam.joNo = joNo;
			linkParam.joEfYd = joEfYd;
			linkParam.mode = 2;
			linkParam.lsId = lsId;
			linkParam.lsiSeq = lsiSeq;
			logger.def("ë²ë ¹ëª x ,lsiSeq ìì ",1);
		} else {
			// ë²ë ¹ëª ì  xx ì¡°
			if (lsIds != null && lsIds != "" 
				&& (lsIds.indexOf("prec") > -1  || lsIds.indexOf("detc") > -1)) {
			    linkParam.joNo = joNo;
				linkParam.joEfYd = joEfYd;
				linkParam.mode = 11;
				linkParam.lsNm = encodeURIComponent(lsNmPara);
				linkParam.ancYd = ancYd;
				linkParam.lsId = lsId;
				linkParam.efYd = lsIds.substring(4, lsIds.length);
				linkParam.lsClsCd = lsClsCd + "L";
				logger.def("ì  xxì¡°",1);
			} else {
				if(lsNmPara.indexOf("ì¡°ë¡")>-1){
					linkParam.joNo = joNo;
					linkParam.mode = 16;
					linkParam.ancYd = ancYd;
					linkParam.lsNm = lsNmPara;
					linkParam.lsClsCd = lsClsCd + "R";
					linkParam.lsId = lsId;
					linkParam.lsiSeq = lsiSeq;
					logger.def("ì¡°ë¡",1);
				} else {
					linkParam.joNo = joNo;
					linkParam.joEfYd = joEfYd;
					linkParam.mode = 10;
					linkParam.lsNm = lsNmPara;
					linkParam.ancYd = ancYd;
					linkParam.lsId = lsId;
					linkParam.lsClsCd = lsClsCd + "L";
					linkParam.ordinNm = lsNmPara;
					logger.def("ì  xxì¡°",1);
				}
			}
		}
	} else {
		
		if(linkLawNm == "Admrul"){
			linkParam.admRulNm = lsNmPara;
			linkParam.lsId = "";
			linkParam.mode = 100;
			linkParam.joNo = joNo;
			linkParam.gubun = "admRul"
		}else{
			// ë²ë ¹ëª ì  xx ì¡° 
			if (lsIds != null && lsIds != ""
				&& (lsIds.indexOf("prec") > -1  || lsIds.indexOf("detc") > -1 || lsIds.indexOf("expc") > -1)) {
				linkParam.joNo = joNo;
				linkParam.joEfYd = joEfYd;
				linkParam.mode = 11;
				linkParam.lsNm = lsNmPara;
				linkParam.ancYd = ancYd;
				linkParam.lsId = lsId;
				linkParam.efYd = lsIds.substring(4, lsIds.length);
				linkParam.lsClsCd = lsClsCd + "L";
				logger.def("ì  xxì¡°",1);
			} else {
				if(lsNmPara.indexOf("ì¡°ë¡")>-1){
					linkParam.joNo = joNo;
					linkParam.mode = 16;
					linkParam.ancYd = ancYd;
					linkParam.lsNm = lsNmPara;
					linkParam.lsClsCd = lsClsCd + "R";
					linkParam.lsId = lsId;
					linkParam.lsiSeq = lsiSeq;
					logger.def("ì¡°ë¡",1);
				} else {
					linkParam.joNo = joNo;
					linkParam.joEfYd = joEfYd;
					linkParam.mode = 4;
					linkParam.lsNm = lsNmPara;
					linkParam.ancYd = ancYd;
					linkParam.lsId = lsId;
					linkParam.lsClsCd = lsClsCd + "L";
					logger.def("ë²ë ¹ëª x",1);
					linkParam.lsiSeq = lsiSeq;
				}
			}
		}
	}
	logger.def(makeParam(linkParam),1);
	lsJoLayNewView(linkParam, linkLawNm);
}
	// commonLsJs.jsp ìì ì´ì¬ì´

/**
 * <pre>
 * 	ì¡°ë¡-ì¡°ë¡ ìíê·ì¹ ììë§í¬ íì
 * </pre>
 * @author yjSeo
 * @since 2023. 09. 21.
 */
function fncOrdinPttnLinkPop(ordinlnkpttnSeq){
	$.ajax({
		method: "POST",
		url: "ordinPttnLinkChk.do",
		dataType:'text',
		data : {ordinlnkpttnSeq : ordinlnkpttnSeq},
		timeout : 10000,
		success:function(data){
			if("jo" == data){
				var url = "ordinLinkPttnPop.do?ordinlnkpttnSeq=" + ordinlnkpttnSeq;
				var size = "width=798, height=681, status=no, toolbar=no, resizable=no, scrollbars=no, menubar=no";
				
				//íìì°½ ì¤ë³µ ì´ë¦¼ ë°©ì§
				window.open(url, '', size);
			} else if("ne" == data){
				var divId = 'joTempDeleLayer';
				lnkTitle = "<div class=\"towp2\" style=\"width:614px;\"><DIV class=ltit2 style=\"width:550px;\" id=\"tmpLtit2Link\">ìì¹ë²ê·</DIV>"
					+"<div class=\"btn22\" style=\"float:right;\">"
					+"<A href=\"#AJAX\" onclick=\"javascript:TempJoDeleLayer.hiddenTempLsLinkLayer();return false;\"><IMG class=maJoHst alt=ë«ê¸° src=\"/LSW/images/button/btn_close8.gif\">"
					+"</A></DIV></div>";
				
				$("#"+divId).html("<div class=\"vwrap4\" style=\"left:0px;height:300px; width: 620px;\" id=\"contwrapLinkDiv\">"+
									"<div class=\"viewla11\" style=\"width: 614px; border-top:1px solid #ffffff;margin-top:-6px; height: 110px;\" id=\"viewLinkDiv\">"+
									"<div style=\"width:590px; height:95px; padding:10px 15px; font-family: Gulim,doutm,tahoma,sans-serif; font-size: 1.1em;\">"+
									"<div class=\"insd\" style=\"height:88px;overflow-x:hidden;overflow-y:hidden;margin-top:-1px;margin-left:0px;margin-right:0px;margin-bottom:0px\" id=\"tmpOrdinLinkDiv\">"+
									"<p style=\"float:left;padding:0; margin:15px 0 0 0\"><img src=\"/LSW/images/icon_alert_cirm2.gif\"></p>"+
									"<ul style=\"float:right;width:550px;\">"+
									"<li style=\"line-height:170%; padding: 0 10px 10px 10px;\"><b>ì¡°ë¬¸ìì ììí ì¬í­ì ê·ì í íììíê·ì¹ì´ ììµëë¤.</b>"+
									"<br>* ìì¸í ì¬í­ì ì§ìì²´ì ë¬¸ìíìê¸° ë°ëëë¤.</li>"+
									"</ul>"+
									"</div>"+
									"</div>"+
									"</div>"+
									"</div>");
				
				TempJoDeleLayer.showTempLsLinkLayer(0,lnkTitle);
			}
		},
		error : function(x, t, m) {
			if(t == "timeout"){
				alert("ì¬ì©ëì´ ë§ì ìëµì´ ì§ì° ììµëë¤ ì ì í ë¤ì ì¬ì©íìê¸° ë°ëëë¤.");
			}
		}
	});
}

/**
 * <pre>
 * 	ìì¹ë²ê· ë§í¬ ì¶ì¶ íì
 * </pre>
 * @author swKim
 * @since 2023. 06. 23.
 */
function fncOrdinLawPop(lsDatId, lsClsCd, gubunCd, stJoNo, stJoDashNo, stJoBrNo,  edJoNo, edJoDashNo, edJoBrNo) {
	
	linkParamDel();
 	var url = "";
 	var lnkGubun = "ordin";
 	linkParam.lnkGubun = lnkGubun;

	if (lsClsCd == "010103") { // ìì¹ë²ê·
		
		if(gubunCd.substring(0,4) == "3004"){
			openPop("ordinBylInfoPLinkR.do?ordinId=" + lsDatId +  "&bylNo=" + stJoNo + "&bylBrNo=" 
					+ stJoBrNo + "&bylClsCd=" + gubunCd + "&lnkGubun=" + lnkGubun);
		} else if(gubunCd == "012601"){
			openPop("ordinLinkProc.do?" + "ordinId=" + lsDatId + "&chrClsCd=" + getValue("lsBdyChrCls") + "&mode=20");		
		} 
		else if (lsDatId != null){
			joLnkShow(lsDatId, lsClsCd, gubunCd, stJoNo, stJoBrNo, edJoNo, edJoBrNo, stJoDashNo, edJoDashNo);								
		} 
	    	 
	} else if(lsClsCd == "010102") {
		if(gubunCd.substring(0,4) == "2002") {
			openPop("admRulBylInfoPLinkR.do?admRulId=" + lsDatId
					+ "&bylNo=" + stJoNo + "&bylBrNo=" + stJoBrNo + "&bylClsCd=" + gubunCd + "&lnkGubun=" + lnkGubun);
		} else if(gubunCd == "012601"){
			openPop("admRulLinkProc.do?" + "admRulId=" + lsDatId + "&chrClsCd=" + getValue("lsBdyChrCls") + "&mode=20" + "&lnkGubun=" + lnkGubun);
		} 
		else if (lsDatId != null){
			joLnkShow(lsDatId, lsClsCd, gubunCd, stJoNo, stJoBrNo, edJoNo, edJoBrNo, stJoDashNo, edJoDashNo);					
		} 
    } else{
    	if (gubunCd.substring(0,4) == "1102") {
    		openPop("lsBylInfoPLinkR.do?lsId=" + lsDatId + "&bylNo=" + stJoNo + "&bylBrNo=" + stJoBrNo + "&bylCls=" + gubunCd + "&lnkGubun=" + lnkGubun);
    	} else if(gubunCd == "012601"){
    		openPop("lsLinkProc.do?" + "lsId=" + lsDatId + "&chrClsCd=" + getValue("lsBdyChrCls") + "&mode=20");
    	} else if (lsDatId != null){
    		joLnkShow(lsDatId, lsClsCd, gubunCd, stJoNo, stJoBrNo, edJoNo, edJoBrNo, stJoDashNo, edJoDashNo);					
    	} 
	}
}

/**
 * <pre>
 * 	ìì¹ë²ê· ë§í¬ ì¡°ë¬¸ íì
 * </pre>
 * @author swKim
 * @since 2023. 06. 23.
 */
function joLnkShow(lsDatId, lsClsCd, gubunCd, stJoNo, stJoBrNo, edJoNo, edJoBrNo, stJoDashNo, edJoDashNo){

	var ordinId    = "";
	var admRulId    = "";
	var lsId = "";
    var lnkGubun = "ordin";
    var joNo = stJoNo + stJoDashNo + stJoBrNo;
    var endJoNo = "";
    
	if(edJoNo){
		endJoNo = edJoNo + edJoDashNo + edJoBrNo;	
	}		
	
	// ìì¹ë²ê· ì²ë¦¬
	if (lsClsCd == "010103") {

	    linkParam.ordinId = lsDatId;
	    linkParam.joNo = joNo;
	    linkParam.endJoNo = endJoNo;
	    linkParam.mode = 4;
	    linkParam.lnkGubun = lnkGubun;
	} else if(lsClsCd == "010102"){ //íì ê·ì¹

	    linkParam.admRulId = lsDatId;
	    linkParam.joNo = joNo;
	    linkParam.endJoNo = endJoNo;
		linkParam.mode = 100;
		linkParam.lnkGubun = lnkGubun;
	} else if (lsClsCd == "010101"){ //ë²ë ¹
		
	    linkParam.lsId = lsDatId;
	    linkParam.joNo = joNo;
	    linkParam.endJoNo = endJoNo;
	    linkParam.mode = 4;
	    linkParam.lnkGubun = lnkGubun;
	} 	
	logger.def(makeParam(linkParam),1);
	lsJoLayNewView(linkParam, lsClsCd);
}

/**
 * <pre>
 * 	ì¡°ë¬¸ ë§í¬ íì
 * </pre>
 * @author brKim
 * @since 2017. 10. 11.
 * @param linkParam
 * @param linkLawNm
 */
function lsJoLayNewView(linkParam, linkLawNm) {

	try {
		
		if (linkLawNm == "Ordin" || linkLawNm == "010103") { // ìì¹ë²ê·
		    fSlimUpdateByNewAjax("lsLinkLayer", "ordinLinkProc.do", makeParam(linkParam));
		} else if (linkLawNm == "Admrul" || linkLawNm == "010102"){
			fSlimUpdateByNewAjax("lsLinkLayer", "admRulLinkProc.do", makeParam(linkParam));
		} else {
			// ì¡°ë¬¸ ìíì¼ì ì ì©ì ìë ì¤ì ì íì´ì£¼ì¸ì.
			linkParam.joEfYd = "";				
		    fSlimUpdateByNewAjax("lsLinkLayer", "lsLinkProc.do", makeParam(linkParam));
		}
		
	} catch(e) {
		logger.err("ì¤ë¥:ì¡°ë¬¸ë§í¬ updateì¤..." + e);
	}
	
	lnkTitle = "<div class=\"towp2\" id=\"towp2Link\"><DIV class=ltit2 id=\"ltit2Link\">ì¡°ë¬¸ì ë³´ </DIV>"
				+"<div class=btn11 style=\"margin-left:65px;\"><a href=\"#\" onclick=\"javascript:fJoHstAll('"+linkLawNm+"');return false;\" title=\"íìì¼ë¡ ì´ë\" style=\"margin-right:4px\"><img alt=ì ì²´ë³´ê¸° src=\"/LSW/images/button/btn_view1.gif\"></a>"
				+"&nbsp;<a href=\"#\" onclick=\"javascript:fJoLnkInfoPrint('"+makeParam(linkParam)+"','"+linkParam.mode+"');return false;\" title=\"íìì¼ë¡ ì´ë\"><img alt=ì¸ì src=\"/LSW/images/button/btn_print3.gif\"></a>" 
				+"</div><div class=\"btn22\" style=\"float:right;\">"
				+"<a href=\"#\" onclick=\"javascript:LsLinkLayer.hiddenLsLinkLayer();return false;\"><img class=maJoHst alt=ë«ê¸° src=\"/LSW/images/button/btn_close8.gif\">"
				+"</a></div></div>";
}

// ë²ë ¹ ëªì´ ë²ì¼ë
function makeLsLNm(lsClsCd){
	var lsNmFull = getValue("lsNmTrim");
	if(lsNmFull != ""){
		if(lsClsCd == 'O'){
			if(lsNmFull.indexOf("ìíë ¹") > -1){
				lsNmFull = lsNmFull.substring(0,lsNmFull.indexOf("ìíë ¹"));
			}
		}else if(lsClsCd == 'R'){
			if(lsNmFull.indexOf("ìíê·ì¹") > -1){
				lsNmFull = lsNmFull.substring(0,lsNmFull.indexOf("ìíê·ì¹"));
			}
		}
		return lsNmFull;
	}else{
		return false;
	}
}

// ë²ë ¹ ëªì´ ìì¼ë
function makeLsONm(lsClsCd){
	var lsNmFull = getValue("lsNmTrim");
	if(lsNmFull != null){
		if(lsNmFull.indexOf("ìíê·ì¹") > -1){
			lsNmFull = lsNmFull.substring(0,lsNmFull.indexOf("ìíê·ì¹"));
			lsNmFull = lsNmFull + "ìíë ¹";
		}
		return lsNmFull;
	}else{
		return false;
	}
}

function fSlimUpdateByOrdinJoConLawAjax(divLayId,urlName,parameter,tempLsNm,txtPara,docType, lsIds, linkLawNm,linkStr,joEfYd,linkJoNo, chkGubun){ // fSlimUpdateByAjax ì ê°ì§ë§ ìì²­ ê²°ê³¼ ê°ì´ ììë ë²ë ¹ë³¸ë¬¸ íì ì¶ê°  2012.07.17
Ext.Ajax.request({
   url: urlName,
    scripts: true,
    params: parameter,
    timeout: 3000000,
    success: function(){
		Ext.get(divLayId).dom.innerHTML = arguments[0].responseText;
		tempLsNm = getValue("lsNm1");
		joInfoShow(tempLsNm,txtPara,docType, lsIds, linkLawNm,linkStr,joEfYd,linkJoNo, chkGubun);		
    }
});
}

/**
 * <pre>
 * 	ì°í ë¡ë© ë° ì¡°í (ì°í ë²í¼)
 * </pre>
 * @author brKim
 * @since 2017. 6. 12.
 * @param divLayId
 * @param urlName
 * @param parameter
 */
function fSlimUpdate(divLayId, urlName, parameter) {
	
	layoutLoadMask(divLayId);
	// ë§ì¤í¬ ìì í íë©´ í¸ì¶
	$("#"+divLayId).load(urlName, parameter,
			function(response, status, xhr) {
				layoutUnMask(divLayId);
			}
	);
}

/**
 * <pre>
 * 	ì°í ë²í¼ ajax ì¡°í (íì¬ ì¡°íì¤ì¸ ë²)
 * </pre>
 * @author brKim
 * @since 2017. 6. 12.
 * @param divLayId
 * @param urlName
 * @param parameter
 */
function fSlimUpdateByAjax(divLayId, urlName, parameter) {
	
	$.ajax({
		url: urlName
	   ,data: parameter
	   ,dataType: "html"
	   ,timeout: 3000000
	   ,success: function(responseText) {
		   
		   $("#"+divLayId).html(responseText);
		   
		   var thdWidthSize = null;
		   
		   if (divLayId == "joHstLayer") {
			   
			   thdWidthSize = $('#joHstLayer').parent().width();
			   
		   } else if (divLayId == "lsLinkLayer") {
			   
				thdWidthSize = $('#lsLinkLayer').parent().width();
				
			    if ($('#lsLinkTable')) {
			    	$('#lsLinkTable').width(thdWidthSize); // 15
			    }
			    
			} else if (divLayId == "unOrdinLayer") {
				
				unOrdinLayer.showUnOrdinLayer(0, "ì¡°ë¡ììì¡°ë¬¸");
				$('#unOrdinLayer').css('height', $("#lelistwrapLeft").height() - 10);
				$('#vwrap2').css('height', $('#lelistwrapLeft').height() - 10);
				$('#viewlaUnOrdin').css('height', $('#lelistwrapLeft').height() - 10);
				$('#unOrdinDiv').css('height', $("#lelistwrapLeft").height() - 10);
				$('#unOrdinIns').css('height', $("#lelistwrapLeft").height() - 10);
				
			} else if (divLayId == "unOrdinJoHstLayer") {
				
				thdWidthSize = $('#unOrdinJoHstLayer').parent().width();
				
				var layWidth = 0;
				
				if ($('#joHstInfoHong').val() == 1) {
					
					layWidth = 407;
					
					$('#viewLaDiv').css('width', '399px');
					$('#hskhskin0').html("");
					
				} else {
					
					layWidth = 800;
					
					$('#viewLaDiv').css('width', '792px');
				}
				
				var title = "<div class=\"Intmodal_header\" style=\"background: url(images/main/mint_hd_Bg_54.gif) repeat-x 100% 0;width:" + (layWidth-7) + "\">" +
						    	"<div>" +
						    		"<h3 class=\"unOrdinlayerH3\" style=\"width:300px; float:left;\">ì¡°ë¬¸ì°í (ê³µí¬ì¼ê¸°ì¤)</h3>" +
						    	"</div>" +
						    	"<div class=\"btn22\" style=\"float:right; padding:4px 4px 0 0;\">" +
						    		"<a href=\"#AJAX\" onclick=\"javascript:unOrdinJoHstLayer.hiddenUnOrdinJoHstLayer();return false;\">" +
						    			"<img class=maJoHst alt=ë«ê¸° src=\"/LSW/images/button/btn_close8.gif\">" +
						    		"</a>" +
						    	"</div>" +
						    "</div>";

				document.getElementById("joHstDiv").scrollLeft = 50000;
				
			} else if (divLayId == "unOrdinReformLayer") {
				
				var listSize = $('#unOrdinReformListSize').val();
				
				if (listSize == 1) {
					
					var goUrl = $('#goUrl').val();
					
					unOrdinReformPop(goUrl);
					
					$('#unOrdinReformDivWrite').html("<div id=\"unOrdinReformLayer\" style=\"display:none\"></div>");
					
				} else {
					
					unOrdinReformLayer.showUnOrdinReformLayer(0,'ê·ì ê°íëìë²ë ¹');
					
				}
			}
		   document.body.style.cursor = "default";
		}
	});
}

/**
 * <pre>
 * 	ì¡°ë¡ììì¡°ë¬¸ ë ì´ì´ í¸ì¶
 * </pre>
 * @author brKim
 * @since 2017. 10. 16.
 * @param divLayId
 * @param urlName
 * @param parameter
 * @param urlGubun
 */
function fSlimUnOrdinUpdateByAjax(divLayId,urlName,parameter, urlGubun) {
	
	$.ajax({
		url: urlName
	   ,data: parameter
	   ,timeout: 3000000
	   ,success: function(responseText) {
			$('#'+divLayId).html(responseText);
			
			if (urlGubun == 'lsSc') {
				$("#unOrdinLayer").css("height", $("#lelistwrapLeft").height() - 10);
				$("#vwrap2").css("height", $("#lelistwrapLeft").height() - 10);
				$("#viewlaUnOrdin").css("height", $("#lelistwrapLeft").height() - 10);
				$("#unOrdinDiv").css("height", $("#lelistwrapLeft").height() - 10);
				$("#unOrdinIns").css("height", $("#lelistwrapLeft").height() - 10);
				unOrdinLayer.showUnOrdinLayer(0, "ì¡°ë¡ììì¡°ë¬¸", 1);
			} else {
				unOrdinLayer.showUnOrdinLayer(0, "ì¡°ë¡ììì¡°ë¬¸", 2);
			}
	   }
	});
	
}

/**
 * <pre>
 * 	ì°í ë²í¼ ajax ì¡°í (íì¬ ì¡°íì¤ì¸ ë²ì ì´ì ë²ë¤)
 * </pre>
 * @author brKim
 * @since 2017. 6. 12.
 * @param divLayId
 * @param urlName
 * @param parameter
 * @param no
 */
function fSlimUpdateByAjaxDiff(divLayId, urlName, parameter, no) {
	layoutLoadMask(divLayId);
	
	$.ajax({
		url: urlName
	   ,data: parameter
	   ,dataType: "html"
	   ,type:'POST'
	   ,timeout: 3000000
	   ,success: function(responseText) {
		   var diffVal = responseText;
		   var diffCut = diffVal.split("diffCut");
		   
		   eval(el("hhhong3"+no)).innerHTML = diffCut[0];
		   eval(el("hhhong1"+(no-1))).style.display = "none";
		   eval(el("hhhong3"+(no))).style.display = "none";
		   if(eval(el("hhhong3"+(no))).style.display == "none"){
			   eval(el("hhhong3"+(no))).style.display = "";
		   }

		   eval(el("hhhong2"+no)).innerHTML = diffCut[1];
		   eval(el("hhhong1"+no)).style.display = "none";
		   eval(el("hhhong2"+(no))).style.display = "none";
		   if(eval(el("hhhong2"+no)).style.display == "none"){
			   eval(el("hhhong2"+(no))).style.display = "";
		   }
		   
		   eval(el("hhhong2"+(no-1))).style.display = "none";
		   eval(el("hhhong3"+(no-1))).style.display = "none";
		   eval(el("hhhong1"+(no-2))).style.display = "";

		   document.body.style.cursor = "default";
		   
		   layoutUnMask(divLayId);
	   }
	});
}

/**
 * <pre>
 * 	ì¡°ë¬¸ ë§í¬ íì (ì ê·)
 *  -> fSlimUpdateByAjax ì ê°ì§ë§ ìì²­ ê²°ê³¼ ê°ì´ ììë ë²ë ¹ë³¸ë¬¸ íì ì¶ê°  2012.07.17
 * </pre>
 * @author brKim
 * @since 2017. 10. 11.
 * @param divLayId
 * @param urlName
 * @param parameter
 */
function fSlimUpdateByNewAjax(divLayId,urlName,parameter) {
	
	
	if (urlName == "lsLinkProc.do") {
		
		//var ran = Math.floor(Math.random() * 100) + 1 ; //20170909  ì¶ê°(íë©´ë¶ë¦¬ ëë¬¸ ì¶ê°)
		var url = "lsLinkProc.do?" + parameter;
		if($openPopWidth == null || $openPopWidth == "" && $openPopHeight == null || $openPopHeight == ""){
			var popupX = (window.screen.width / 2) - (800 / 2);
			var popupY = (window.screen.height / 2) - (270 / 2);
			var win = window.open(url,'ì¡°ë¬¸ì ë³´', 'scrollbars=yes,toolbar=no,resizable=yes,status=no,menubar=no,width=800px,height=266px,left=' + popupX + ',top=' + popupY);
			return false;
		}else{
			var popupX = (window.screen.width / 2) - ($openPopWidth / 2);
			var popupY = (window.screen.height / 2) - ($openPopHeight / 2);
			var win = window.open(url, 'ì¡°ë¬¸ì ë³´', 'scrollbars=yes,toolbar=no,resizable=yes,status=no,menubar=no,width=' + $openPopWidth + ',height=' + $openPopHeight + ',left=' + popupX + ',top=' + popupY);
			return false;
		}
	}else if(urlName == "ordinLinkProc.do") {
		
		var url = "ordinLinkProc.do?" + parameter;
		if($openPopWidth == null || $openPopWidth == "" && $openPopHeight == null || $openPopHeight == ""){
			var popupX = (window.screen.width / 2) - (800 / 2);
			var popupY = (window.screen.height / 2) - (270 / 2);
			var win = window.open(url,'ì¡°ë¬¸ì ë³´', 'scrollbars=yes,toolbar=no,resizable=yes,status=no,menubar=no,width=800px,height=266px,left=' + popupX + ',top=' + popupY);
			return false;
		}else{
			var popupX = (window.screen.width / 2) - ($openPopWidth / 2);
			var popupY = (window.screen.height / 2) - ($openPopHeight / 2);
			var win = window.open(url, 'ì¡°ë¬¸ì ë³´', 'scrollbars=yes,toolbar=no,resizable=yes,status=no,menubar=no,width=' + $openPopWidth + ',height=' + $openPopHeight + ',left=' + popupX + ',top=' + popupY);
			return false;
		}
	}else if(urlName== "admRulLinkProc.do"){
		
		var url = "admRulLinkProc.do?" + parameter;
		if($openPopWidth == null || $openPopWidth == "" && $openPopHeight == null || $openPopHeight == ""){
			var popupX = (window.screen.width / 2) - (800 / 2);
			var popupY = (window.screen.height / 2) - (270 / 2);
			var win = window.open(url,'ì¡°ë¬¸ì ë³´', 'scrollbars=yes,toolbar=no,resizable=yes,status=no,menubar=no,width=800px,height=266px,left=' + popupX + ',top=' + popupY);
			return false;
		}else{
			var popupX = (window.screen.width / 2) - ($openPopWidth / 2);
			var popupY = (window.screen.height / 2) - ($openPopHeight / 2);
			var win = window.open(url, 'ì¡°ë¬¸ì ë³´', 'scrollbars=yes,toolbar=no,resizable=yes,status=no,menubar=no,width=' + $openPopWidth + ',height=' + $openPopHeight + ',left=' + popupX + ',top=' + popupY);
			return false;
		}		
	}else{
		$.ajax({
			url: urlName
		   ,data: encodeURI(parameter)
		   ,timeout: 3000000
		   ,success: function(responseText) {
			   $("#"+divLayId).html(responseText);
			   if (responseText.length < 1000 && el('lnkLsNm') != null) {

				   // ë²ë ¹ë§ ì ì©, lnkLsNm ê°ì ë²ë ¹(lsLink.jsp)ë§ ì¤ì 
				   // ë²ë ¹ì¸ ì ì©ì lnkLsNm ê°ì ì¤ì í´ì£¼ë©´ ë¨.
				   // ì¡°ë¬¸ì ë³´ê° ìë ê²½ì° ë²ë ¹ì ë³´ íìì í¸ì¶íë¤.		
				   var lnkLsNm = getValue('lnkLsNm');
				   var lnkLsiSeq = getValue('lnkLsiSeq');
				   var lnkLsId = getValue('lnkLsId');
				   
				   if (lnkLsNm != "") {
					   var url = "lsLinkProc.do?" + "lsNm=" + encodeURIComponent(lnkLsNm) 
					   			+ "&joLnkStr=" + "&chrClsCd=" + linkParam.chrClsCd + "&mode=20";
					   openPop(url, 1000);
				   } else {
					   LsLinkLayer.showLsLinkLayer(0,lnkTitle);
				   }
				   
			   } else {
				   
				   if (divLayId=="joHstLayer") {
					   var thdWidthSize = $('#joHstLayer').parent().width();
				   }
				   
				   LsLinkLayer.showLsLinkLayer(0,lnkTitle);
				   
				   if (divLayId == "lsLinkLayer" && urlName == "ordinLinkProc.do") {
			   			$("#towp2Link").css("margin-top","-30px");
			   		}
				}
		   }
		});
	}
}

	

/**
 * <pre>
 * 	ì¡°ë¬¸ ë§í¬ ì¸ì
 * </pre>
 * @author brKim
 * @since 2017. 10. 11.
 * @param para
 * @param mode
 */
function fJoLnkInfoPrint(para,mode) {
	
    var para = para;
    var url = "";
    
    if (!para) {
    	para = document.location.href.split("?").pop();
    	para = decodeURIComponent(para);
    }
    
	if (mode == 10) {
		var lsId = getValue("lnkLsId");
		url = "lsLinkProc.do?"+para+"&lsId="+lsId+"&print=print";
		
		if (para.indexOf("lsJoLnkSeq") > -1) {
			url = "lsLawLinkInfo.do?"+para+"&lsId="+lsId+"&print=print";
		} else if (para.indexOf("lsId=") > -1) {
			url = "lsLinkProc.do?"+para+"&print=print";
		}
	} else if (mode == 100) {
		url = "admRulLinkProc.do?"+para+"&print=print";
	} else if (mode == 2) {
		para = decodeURIComponent(para);
		url = "ordinLinkProc.do?"+para+"&print=print";
	}else {
		url = "lsLinkProc.do?"+para+"&print=print";
	}
	
	openPrintPop(url,"ì¡°ë¬¸ì ë³´ì¶ë ¥");
}

function fncArLawPop(lsNm, ancYd, ancNo){
	var para = "&lsNm=" + encodeURIComponent(lsNm)
	          +"&ancYd=" + ancYd
	          +"&chrClsCd=010202&urlMode=lsRvsDocInfoR"
	          +"&ancNo=" + ancNo;
	var url = "lsSideInfoP.do?"+ para;
	openPop(url);
}


function fncArOrdinPop(ordinNm, ancYd, ancNo){
	var url = "ordinSideInfoP.do?ordinNm=" + encodeURIComponent(ordinNm) + "&ancYd=" + ancYd +"&ancNo="+ancNo + "&urlMode=ordinRvsDocInfoR&chrClsCd=010202";
	openPop(url);
}

/**
 * ì¡°ë¬¸ì ë³´ - ë²ë ¹ë³´ê¸°
 * <shcho> ìíì¼/ê³µí¬ ìë¹ì¤ê°ì  : ìí,ì°í(ancYnChk: 0=ìí, 1=ê³µí¬) íë¼ë¯¸í° ì¶ê°
 */
function fJoHstAll(linkLawNm){
	
	var url = "";
	if(linkLawNm == "Ordin") {
		url = "ordinInfoP.do?ordinSeq="+ el('lnkOrdinSeq').value;
	}else {
		url = "lsInfoP.do?lsiSeq="+ el('lnkLsiSeq').value +"&ancYnChk=" + el("ancYnChk").value;
	}
	
	openPop(url);
}

//ì¡°ë¬¸ì²´ê³ë íì

function joStmdPop(lsiSeq, joNo, joBrNo){
    var url = "joStmdInfoP.do?lsiSeq=" + lsiSeq + "&joNo=" + joNo + "&joBrNo=" + joBrNo; 

	openScrollPop(url,"1024px");
}


//ë²ë ¹ì²´ê³ë íì
/*
* 2019.08.23 <shcho> ìíì¼/ê³µí¬ ìë¹ì¤ê°ì  : ìí,ì°í(ancYnChk: 0=ìí, 1=ê³µí¬) íë¼ë¯¸í° ì¶ê° *
	 * - ìì¸ë³ê²½ë´ì­ : + "&ancYnChk=" +ancYnChk; ì¶ê°
*/
function lsStmdPop(lsiSeq, ancYnChk) {
	
	if(lsiSeq == '' && el("lsiSeq")){
		lsiSeq = el("lsiSeq").value;
	}
	
	if(ancYnChk == '' && el("ancYnChk")) {
		ancYnChk = el("ancYnChk").value;
	}
	
	if (el("lsiSeq")) {
		var url = "lsStmdInfoP.do?lsiSeq=" + lsiSeq + "&ancYnChk=" +ancYnChk; 
		openScrollPop(url, "1024px");
	} else {
		alert(lsVO.msg);
	}
}

// íë¡ì²´ê³ë íì
function precStmdPop(precSeq){
	var url = "precStmdInfoP.do?precSeq=" + precSeq; 

	openScrollPop(url, "1024px");
}

// íì¬ê²°ì ë¡ì²´ê³ë íì
function detcStmdPop(detcSeq){
	var url = "detcStmdInfoP.do?detcSeq=" + detcSeq; 
	
	openScrollPop(url, "1024px");
}

// í´ìë¡ì²´ê³ë íì
function expcStmdPop(expcSeq){
	var url = "expcStmdInfoP.do?expcSeq=" + expcSeq; 
	
	openScrollPop(url, "1024px");
}

function cgmExpcStmdPop(cgmExpcDatSeq){
	if (cgmExpcDatSeq == '' || cgmExpcDatSeq == 0) {
		alert('ë³¸ë¬¸ì ì ííì­ìì.');
		return;
	}

	var url = "cgmExpcStmdInfoP.do?cgmExpcDatSeq=" + cgmExpcDatSeq;

	openScrollPop(url, "1024px");
}

// ì¬íë¡ì²´ê³ë íì
function deccStmdPop(deccSeq){
	var url = "deccStmdInfoP.do?deccSeq=" + deccSeq; 
	
	openScrollPop(url, "1024px");
}

// ì¬íë¡ì²´ê³ë íì
function specialDeccStmdPop(deccSeq, trbClsCd){
	if (deccSeq == '' || deccSeq == 0) {
		alert('ë³¸ë¬¸ì ì ííì­ìì.');
		return;
	}
	var url = "specialDeccStmdInfoP.do?specialDeccSeq=" + deccSeq + "&trbClsCd=" + trbClsCd;

	openScrollPop(url, "1024px");
}

//ë²ë ¹ì²´ê³ë íì(ê¸°í[ì ê°ì ë¬¸] íë©´ìì í¸ì¶í ë)
function lsStmdEtcPop(lsiSeq, prcDv){
	var url = "lsStmdInfoP.do?lsiSeq=" + lsiSeq + "&prcDv=" + prcDv;

	openScrollPop(url, "1024px");
}
//ë²ë ¹ì°ê³ ìì¸ë³´ê¸°   2014.06.10    
 //2014.08.08 ìì¸ë³´ê¸° ë²í¼ ìì´ì§
/*
var isEnableLink = false;

function enableLink(){
	if(isEnableLink){
		$("a[name='detailLink']").each(function(){
			$(this).attr('class', 'disableLink');
			$(this).attr('title', '');
		});
		isEnableLink = false;
	} else {
		$("a[name='detailLink']").each(function(){
			$(this).attr('class', 'enableLink');
			$(this).attr('title', 'íìì¼ë¡ ì´ë');
		});
		isEnableLink = true;
	}
}
*/
//ë²ë ¹ì°ê³ íìì°½ ì¤ë³µ ì´ë¦¼ ë°©ì§ 2014.06.25
var childWin = null;

function openLinkPop(popObj){
	if(childWin){
	//alert("ì´ë¯¸ íìì°½ì´ ì´ë ¤ ììµëë¤.");
		childWin.focus();
	}else{
		childWin = popObj;
	}
	return childWin;
}

// 2014.10.08 ììë²ë ¹íìì°½
function devLawPop(type, lsiSeq, joNo, joBrNo, lnkText){
	var datClsCd = "";
	
	if(type == 'ordin'){
		datClsCd = "010103";
		
		
	}else if(type == 'admrul'){
		datClsCd = "010102";
		
	}else if(type == 'excuAdmrul'){
		datClsCd = "010102";
	}
	var action = "";
	if('010102' == datClsCd){
		if(type == 'admrul'){
			action = "conAdmrulByLsPop.do?";			
		}else if(type == 'excuAdmrul'){
			action = "conExcuAdmrulByLsPop.do?";
		}
	} else {
		action = "lumLsDevPop.do?";
	}
	
	var url = action
	+ "lsiSeq=" + lsiSeq
	+ "&datClsCd=" + datClsCd;
	
	if(typeof joNo != 'undefined'){
		url = url + "&joNo=" + joNo;
		if(typeof joBrNo != 'undefined'){
			url = url + "&joBrNo=" + joBrNo
		}
	}
	
	if(typeof lnkText != 'undefined'){
		url = url + "&lnkText=" + lnkText;
	}

//	var size = "width=798, height=681, status=no, toolbar=no, resizable=no, scrollbars=no, menubar=no, scrollbars=yes";
	var size = "width=1024, height=630, scrollbars=no, toolbar=no, resizable=yes, status=no, location=yes, menubar=no, scrollbars=yes, resizable=yes";
	
	//window.open(url, '', size);
	
	//íìì°½ ì¤ë³µ ì´ë¦¼ ë°©ì§  2014.06.25
     var popObj = window.open(url, 'lsDevPop', size);
	//var popObj = window.open(url, 'lsDevPop');
	openLinkPop(popObj);
	
	
	return;

}


/* ìë°ì¤í¬ë¦½í¸ììë ì¤ë²ë¡ë©ì ì§ìíì§ ìì¼ë¯ë¡ í­ì ìëì ë©ìëê° ëìí¨ ì£¼ì ì²ë¦¬
 * ë²ë ¹ìì ììë íì ê·ì¹ ,ìì ìì¹ë²ê·ì ì¡°ë¬¸ì ì²´ ëë ë¬¸ìì´ ë§í¬ë¥¼ ìí function   
function joDelegatePop(lsiSeq, joNo, joBrNo, datClsCd, lnkText){
	var size = "width=798, height=681, status=no, toolbar=no, resizable=no, scrollbars=no, menubar=no, scrollbars=yes";
	//var size = "width=1024, height=630, scrollbars=no, toolbar=no, resizable=yes, status=no, location=yes, menubar=no, scrollbars=yes, resizable=yes";
	var action = "";
	if('010102' == datClsCd){
		action = "conAdmrulByLsPop.do?";
	} 
//	else {
//		action = "lumLsDevPop.do?";
//	}
	var url = action
		+ "&lsiSeq=" + lsiSeq
		+ "&joNo=" + joNo
		+ "&joBrNo=" + joBrNo
		+ "&datClsCd=" + datClsCd;

	if(typeof lnkText != 'undefined'){
		if('010102' == datClsCd){
			url = url + "&lnkText=" + lnkText;
		}
	}
	
	var popObj = window.open(url, 'lsDevPop', size);
	openLinkPop(popObj);
}	*/

var joDele = {lsiSeq : ""
	 ,joNo : ""
	 ,joBrNo : ""
	 ,datClsCd : ""
	 ,dguBun : ""};


var joDeleGateParam = {lsiSeq : ""
	,joNo : ""
	,joBrNo : ""
	,datClsCd : ""
	,dguBun : ""
};

/* ë²ë ¹ìì ììë íì ê·ì¹ ,ìì ìì¹ë²ê·, ìì ê·ì ì ì¡°ë¬¸ì ì²´ ëë ë¬¸ìì´ ë§í¬ë¥¼ ìí function */
function joDelegatePop(lsiSeq, joNo, joBrNo, datClsCd, dguBun, lnkText, pttnSeq){

	if ('NOT' == dguBun) {
		var size = "width=710, height=230, scrollbars=no, toolbar=no, resizable=yes, status=no, location=yes, menubar=no, scrollbars=no, resizable=yes";
	} else {
		var size = "width=1040, height=630, scrollbars=no, toolbar=no, resizable=yes, status=no, location=yes, menubar=no, scrollbars=yes, resizable=yes";
		
		if (typeof lnkText == 'undefined') {
			dguBun = "DEG";
		}
	}
	
	var action = "";
	joDele.lsiSeq = lsiSeq;
	joDele.joNo = joNo;
	joDele.joBrNo = joBrNo;
	joDele.datClsCd = datClsCd;
	joDele.dguBun = dguBun;

	if ('010102' == datClsCd) {
		action = "conAdmrulByLsPop.do";
	}else if ('010113' == datClsCd) {
		action = "conSchlPubRulByLsPop.do";
	}
	
	var url = action
		+ "?&lsiSeq=" + lsiSeq
		+ "&joNo=" + joNo
		+ "&joBrNo=" + joBrNo
		+ "&datClsCd=" + datClsCd
		+ "&dguBun=" + dguBun;

	if (typeof lnkText != 'undefined') {
		if ('010102' == datClsCd || '010113' == datClsCd) {
			url = url + "&lnkText=" + encodeURI(encodeURIComponent(lnkText));
		}
	}
	
	// [2017êµ­ë²ê°ë°] pttnSeq ì ì© Start
	if(typeof pttnSeq != 'undefined'){
		if('DEG' == dguBun) {
			if ('010102' == datClsCd) {
				url = url + "&admRulPttninfSeq=" + pttnSeq;
			}else if ('010113' == datClsCd) {
				url = url + "&schlPubRulPttninfSeq=" + pttnSeq;
			}
		}
	}
	// [2017êµ­ë²ê°ë°] pttnSeq ì ì© End
	
	if ('NOT' == dguBun) {
		lnkTitle = "<div class=\"towp2\" style=\"width:614px;\"><DIV class=ltit2 style=\"width:550px;\" id=\"ltit2Link\">íì ê·ì¹</DIV>"
			+"<div class=\"btn22\" style=\"float:right;\">"
			+"<A href=\"#AJAX\" onclick=\"javascript:JoDeleLayer.hiddenLsLinkLayer();return false;\"><IMG class=maJoHst alt=ë«ê¸° src=\"/LSW/images/button/btn_close8.gif\">"
			+"</A></DIV></div>";
		joDelegateAjax(action, makeParam(joDele));
	} else {
		var popObj = window.open(url, 'lsDevPop', size);
		openLinkPop(popObj);
	}
}

/*ë²ë ¹ìì ìììì¹ë²ê· íì*/
function joDelegateOrdinPop(lsiSeq, lsId, joNo, joBrNo, datClsCd, dguBun, lnkText){
	
	var size = "width=" + screen.width + ", height=700, scrollbars=no, toolbar=no, resizable=yes, status=no, location=yes, menubar=no, scrollbars=no, resizable=yes,top=1,left=1";
	if(typeof lnkText == 'undefined') dguBun = "DEG";
	
	var action = "";
	joDele.lsiSeq = lsiSeq;
	joDele.joNo = joNo;
	joDele.joBrNo = joBrNo;
	joDele.datClsCd = datClsCd;
	joDele.dguBun = dguBun;
	
	action = "lumThdCmpJo.do";
		
	var url = action
		+ "?lsiSeq=" + lsiSeq
		+ "&joNo=" + joNo
		+ "&joBrNo=" + joBrNo
		+ "&datClsCd=" + datClsCd
		+ "&dguBun=" + dguBun;

	lnkText = lnkText.replaceAll("Â·", ".");
	lnkText = lnkText.replaceAll("ã", ".");
	
	if(typeof lnkText != 'undefined'){
		url = url + "&lsId=" + lsId + "&chrClsCd=010202&gubun=STD&lnkText=" + encodeURI(encodeURIComponent(lnkText));
	}
		var popObj = window.open(url, 'lsDevOrdinPop2', size);
		openLinkPop(popObj);
}

/*ë²ë ¹ìì ê´ë ¨ì¡°ë¡*/
function joDelegateLsPop(lsiSeq, lsId){
	
	if(lsiSeq == '' && el("lsiSeq")){
		lsiSeq = el("lsiSeq").value;
	}
	
	var size = "width=" + screen.width + ", height=700, status=no, toolbar=no, resizable=no, scrollbars=no, menubar=no, scrollbars=yes,top=1,left=1";
			
	var action = "lumThdCmpJo.do?";
	var param = "lsiSeq=" + lsiSeq + "&joNo=&joBrNo=&datClsCd=010103&LsAll=Y&chrClsCd=010202&gubun=STD"; 
	var url = action + param;
	
	var popObj = window.open(url, 'lsDevPop2', size);
	openLinkPop(popObj);
	popObj.focus(); 
}

/* ì¡°ë¡ ììì¡°ë¬¸ ë ì´ì´ */
var unOrdinLayer = (function() {
	var unOrdinLayer = null;
	return {
		showUnOrdinLayer : function(size, title, gubun) {
			
				//var title = '<div><img src="/LSW/images/cssimg/tab99.gif" alt="ìì¹ë²ê· ìì ê·¼ê±° ì¡°ë¬¸" title="ìì¹ë²ê· ìì ê·¼ê±° ì¡°ë¬¸"/><span style="float: right;">X</span></div>';
				var title =	'<div class="vwrap_tit">'
		    		+	'<h5 class="vw_s_tit"><span>ìì¹ë²ê· ìì ê·¼ê±° ì¡°ë¬¸</span></h5>'
		    		+	'<a href="#" onclick="unOrdinLayer.hiddenUnOrdinLayer();" class="btn_vw_clse">ë«ê¸°</a>'
		    		+'</div>';
				
				if (!unOrdinLayer) {
				
					unOrdinLayer = $("#unOrdinLayerBtn").dialog({
						autoOpen : false
						   ,modal : false
						   ,resizable: false
						   ,width: '250px'
						   ,title : title
						   ,position : {
								// ë´ ê°ì²´ ìì¹
								my : 'left top',
								// ì°¸ì¡°í  ê°ì²´ ìì¹
								at : 'left top',
								// ì°¸ì¡°í  ê°ì²´ ì§ì 
								of : $('#leftContent')
						   }
					});
				
				}
				unOrdinLayer.dialog("open");			
		}
		,hiddenUnOrdinLayer : function(){		
			if (unOrdinLayer) {
				$("#unOrdinLayerBtn").empty();
				unOrdinLayer.dialog("close");
			}
		}
	};
})();


var unOrdinJoHstLayer = function(){
	var unOrdinJoHstLayer;
	return {
		showUnOrdinJoHstLayer : function(size, title, layWidth){
			if(size==0){
					if(unOrdinJoHstLayer){
						unOrdinJoHstLayer.hide();
					}
					title = title.replace("ì¡°ë¬¸ì ë³´", "ì¡°ë¬¸ì°í");
					unOrdinJoHstLayer = $("#unOrdinJoHstLayer").dialog({
						autoScroll:false
						,title: title
						,width:layWidth  // 800
						,height:450 // 450
						,modal : false
						,resizable : false
						,position : {
							// ë´ ê°ì²´ ìì¹
							my : 'center',
							// ì°¸ì¡°í  ê°ì²´ ìì¹
							at : 'center',
							// ì°¸ì¡°í  ê°ì²´ ì§ì 
							of : 'body'
						}
						
				});
					
				// íì´í ë° ì¨ê¹
			    //unOrdinJoHstLayer.parent().find('.ui-dialog-titlebar').hide();
				unOrdinJoHstLayer.dialog("open");
 			}
			
			if(size!=0){
				if(size<100){
					win5.setHeight(size+120);
				}else if(size>100 && size < 220){
					win5.setHeight(size+80);
				}		
			}
		}
		,hiddenUnOrdinJoHstLayer : function(){
			if(unOrdinJoHstLayer){
				unOrdinJoHstLayer.dialog('close');
			}
		}
	};
}();

/**
 * <pre>
 * 	ììíì ê·ì¹ íì ë ì´ì´
 * </pre>
 * @author brKim
 * @since 2017. 11. 27.
 */
var JoDeleLayer = function() {
	
	var joDeleLayer = null;
	
	return {
		showLsLinkLayer : function(size, title) {
			
			if (size == 0) {
				
				if (joDeleLayer) {
					joDeleLayer.dialog("close");
				}
				
				$('#lsLinkLayer').show();
				
				joDeleLayer = $('#lsLinkLayer').dialog({
	            	autoOpen : false
	               ,width: 620
	               ,height: 145
	               ,modal: false
				   ,title: title
				   ,resizable: false
				   ,position: {
						// ë´ ê°ì²´ ìì¹
						my : 'center',
						// ì°¸ì¡°í  ê°ì²´ ìì¹
						at : 'center',
						// ì°¸ì¡°í  ê°ì²´ ì§ì 
						of : 'body'
					}
	            });
				
				joDeleLayer.dialog("open");
			}
		}
		,hiddenLsLinkLayer: function() {
			if (joDeleLayer) {
				joDeleLayer.dialog("close");	
			}
		}
	};
}();
//ê·ì ê°í ëìë²ë ¹
var unOrdinReformLayer = function(){
	var unOrdinReformLayer;
	return {
		showUnOrdinReformLayer : function(size, title){
			if(size==0){
				if(unOrdinReformLayer){
					unOrdinReformLayer.hide();
				}

				Ext.get('unOrdinReformLayer').show();
				
				unOrdinReformLayer = new Ext.Window({
//					title:'<img src="/images/cssimg/tab99.gif" alt="ìì¹ë²ê· ìì ê·¼ê±° ì¡°ë¬¸" title="ìì¹ë²ê· ìì ê·¼ê±° ì¡°ë¬¸"/>'
					title:'<img src="/images/intpop/tab100.gif" alt="ê·ì ê°í ëìë²ë ¹" title="ê·ì ê°í ëìë²ë ¹"/>'
					,width:275
					,height:390
					,closable: true
					,closeAction:'close'
					,autoScroll:false
					,layout: {
				        type: 'fit',
				        align: 'center'
				    }
				    ,defaults: {
				        bodyPadding: 0
				    }
				    ,resizable: false
					,contentEl : 'unOrdinReformLayer'
					,baseCls:'o-window'
					,cls:'owindow'
					,border: false
					,listeners:{'resize':function(){
			           }
    				   ,'close':function(){
    				       el("unOrdinReformDivWrite").innerHTML = "<div id=\"unOrdinReformLayer\" style=\"display:none\"></div>";
    		           }
					}
			    });
				unOrdinReformLayer.show();
			}
		}
		,hiddenUnOrdinReformLayer : function(){
			if(unOrdinReformLayer){
				unOrdinReformLayer.close();			
			}
		}
	};
}();

function joDelegateAjax(urlName,parameter) {
	
	var divId = 'lsLinkLayer';
	
	$.ajax({
		url: urlName
	   ,data : parameter
       ,timeout: 3000000
       ,dataType: "html"
       ,method: "POST"
       ,success: function(responseText){
    	   $('#'+divId).html(responseText);
    	   JoDeleLayer.showLsLinkLayer(0, lnkTitle);
       	}
	});
}

/**
 * <pre>
 * 	ë²ë ¹ ë³¸ë¬¸ì ììíì ê·ì¹ ëª©ë¡ì ë³´ì¬ì¤ë¤.
 * </pre>
 * @author brKim
 * @since 2017. 9. 26.
 * @param htmlId
 * @param pLsiSeq
 * @param pJoNo
 * @param pJoBrNo
 * @param pDatClsCd
 * @param pCptYn
 */
function viewDelegatedAdmRul(htmlId, pLsiSeq, pJoNo, pJoBrNo, pDatClsCd, pCptYn) {
	
	var urlName = webRoot + "/lsiJoDelegatedAdmRul.do";
	var parameter = "lsiSeq=" + pLsiSeq
		+ "&joNo=" + pJoNo
		+ "&joBrNo=" + pJoBrNo
		+ "&datClsCd=" + '010102';

	if (!$('#delegated_' + htmlId).html() || !$.trim($('#delegated_' + htmlId).html())) {
		
		$.ajax({
			    url:		urlName
			   ,data:		parameter
			   ,timeout:	3000000
			   ,dataType:	'html'
			   ,success:	function(responseText) {
		        	$('#delegated_' + htmlId).html(responseText);
		        	$("#delegated_"+htmlId).css("display", "block");
		        	$("#delegatedAdmRul_img_"+htmlId).attr("src", webRoot + "/images/common/btn_rule_close.gif");
				}
		});
		
	} else {
		
		if ($("#delegated_"+htmlId).css("display") == "none") {
			$("#delegated_"+htmlId).css("display", "block");
			$("#delegatedAdmRul_img_"+htmlId).attr("src", webRoot + "/images/common/btn_rule_close.gif");
		} else {
			$("#delegated_"+htmlId).css("display", "none");
			$("#delegatedAdmRul_img_"+htmlId).attr("src", webRoot + "/images/common/btn_rule_view.gif");
		}
		
	}
}

/*ìììì¹ë²ê· íì¤í¸*/
function devLawPopTest(type, lsiSeq, joNo, joBrNo, lnkText){
	var datClsCd = "";
	
	if(type == 'ordin'){
		datClsCd = "010103";
	}	
		
	action = "unOrdinListTest.do?";
	
	var url = action
	+ "lsiSeq=" + lsiSeq
	+ "&datClsCd=" + datClsCd;
	
	if(typeof joNo != 'undefined'){
		url = url + "&joNo=" + joNo;
		if(typeof joBrNo != 'undefined'){
			url = url + "&joBrNo=" + joBrNo
		}
	}
	
	if(typeof lnkText != 'undefined'){
		url = url + "&lnkText=" + lnkText;
	}

//	var size = "width=798, height=681, status=no, toolbar=no, resizable=no, scrollbars=no, menubar=no, scrollbars=yes";
	var size = "width=722, height=630, scrollbars=no, toolbar=no, resizable=yes, status=no, location=yes, menubar=no, scrollbars=yes, resizable=yes";
	
	//window.open(url, '', size);
	
	//íìì°½ ì¤ë³µ ì´ë¦¼ ë°©ì§  2014.06.25
     var popObj = window.open(url, 'lsDevPop', size);
	//var popObj = window.open(url, 'lsDevPop');
	openLinkPop(popObj);
	
	
	return;

}

function joDelegatePopUnordin(lsiSeq, joNo, joBrNo, datClsCd, dguBun, lnkText){
//	alert(dguBun);
	
	//var size = "width=798, height=681, status=no, toolbar=no, resizable=no, scrollbars=no, menubar=no, scrollbars=yes";
	if('NOT' == dguBun){
		var size = "width=710, height=230, scrollbars=no, toolbar=no, resizable=yes, status=no, location=yes, menubar=no, scrollbars=no, resizable=yes";
	}else{
		var size = "width=1024, height=630, scrollbars=no, toolbar=no, resizable=yes, status=no, location=yes, menubar=no, scrollbars=yes, resizable=yes";
		
		if(typeof lnkText == 'undefined') dguBun = "DEG"
	}
	var action = "";
	joDele.lsiSeq = lsiSeq;
	joDele.joNo = joNo;
	joDele.joBrNo = joBrNo;
	joDele.datClsCd = datClsCd;
	joDele.dguBun = dguBun;
	action = "unOrdinLnk.do";
	
	var url = action
		+ "?&lsiSeq=" + lsiSeq
		+ "&joNo=" + joNo
		+ "&joBrNo=" + joBrNo
		+ "&datClsCd=" + datClsCd
		+ "&dguBun=" + dguBun;

	if(typeof lnkText != 'undefined'){
		if('010102' == datClsCd){
			url = url + "&lnkText=" + encodeURI(encodeURIComponent(lnkText));
		}
	}
	if('NOT' == dguBun){
		lnkTitle = "<div class=\"towp2\" style=\"width:614px;\"><DIV class=ltit2 style=\"width:550px;\" id=\"ltit2Link\">íì ê·ì¹</DIV>"
			+"<div class=\"btn22\" style=\"float:right;\">"
			+"<A href=\"#AJAX\" onclick=\"javascript:JoDeleLayer.hiddenLsLinkLayer();return false;\"><IMG class=maJoHst alt=ë«ê¸° src=\"/LSW/images/button/btn_close8.gif\">"
			+"</A></DIV></div>";
		joDelegateAjax(action,makeParam(joDele));
	}else{
		var popObj = window.open(url, 'unOrdinLnkPop', size);
		openLinkPop(popObj);
	}
}	

function unOrdinLsPop(gubun, lsiSeq) {
	
	action = "unOrdinLsList.do";
	var size = "width=528, height=613, scrollbars=no, toolbar=no, resizable=yes, status=no, location=yes, menubar=no, scrollbars=yes, resizable=yes";
	var url = action
		+ "?&lsiSeq=" + lsiSeq;
	var popObj = window.open(url, 'unordinLsPop', size);
	openLinkPop(popObj);
}

/**
 * <pre>
 * 	ê´ë ¨ê·ì  ëª©ë¡ Layer ìì±
 * </pre>
 * @author brKim
 * @since 2017. 10. 18.
 */
var ctlInfLayer = function() {
	
    var ctlInfLayer = null;
    
    return {
    	
    	showCtlInfLayer : function() {
    		
    		if (ctlInfLayer) {
                ctlInfLayer.dialog("close");
            }
    		
    		if (!ctlInfLayer) {
    			
    			var title =	'<div class="vwrap_tit">'
		    				+	'<h5 class="vw_s_tit"><span>ê·ì ì¬ë¬´ëª</span></h5>'
		    				+	'<a href="#" onclick="ctlInfLayer.hiddenCtlInfLayer();" class="btn_vw_clse">ë«ê¸°</a>'
		    				+'</div>';
    			
    			ctlInfLayer = $('#ctlInfLayer').dialog({
    				autoOpen: false
    				,width: 250
    				,height: 350
    				,modal: false
    				,title: title
    				,resizable: false
    				,position: {
    					// ë´ ê°ì²´ ìì¹
    					my : 'left top',
    					// ì°¸ì¡°í  ê°ì²´ ìì¹
    					at : 'left top',
    					// ì°¸ì¡°í  ê°ì²´ ì§ì 
    					of : $('#leftContent')
    				}
    			});
    		}
    		
    		ctlInfLayer.dialog("open");
        }
        ,hiddenCtlInfLayer : function(){
            if (ctlInfLayer) {
                ctlInfLayer.dialog("close");
            }
        }
    };
}();

/**
 * <pre>
 * 	ê·ì  íì (ì¡°ë¬¸ ì ê· ë²í¼)
 * </pre>
 * @author brKim
 * @since 2017. 10. 18.
 * @param lsiSeq
 * @param lsId
 * @param joNo
 * @param joBrNo
 */
function openCtlInfoList(lsiSeq, lsId, joNo, joBrNo) {
	
    var urlName = "lsCtlInfListR.do";
    var parameter = "lsiSeq="+lsiSeq
        +"&lsId="+lsId
        +"&joNo="+joNo
        +"&joBrNo="+joBrNo;

    $.ajax({
    	url: urlName
       ,data: parameter
       ,timeout: 3000000
       ,success: function(responseText) {
            $("#ctlInfLayer").html(responseText);
            var layerHeight = 320;
            if ($("#ctlinfSize").val() == 1) {
                openCtlInfPop($("#ctlInfUrl").val());
            } else {
                $("#ctlInfLayer").css("height", layerHeight);
                $("#vwrap2").css("height", layerHeight);
                $("#viewlaCtlInf").css("height", layerHeight);
                $("#ctlInfDiv").css("height", layerHeight);
                ctlInfLayer.showCtlInfLayer();
            }
        }
    });
}

//2015.09.25 [LSI2015]  ê´ë ¨ê·ì  URL íìí¸ì¶
/**
 * <pre>
 * 	ê´ë ¨ê·ì  URL íìí¸ì¶
 * </pre>
 * @author brKim
 * @since 2017. 10. 18.
 * @param url
 * @returns {Boolean}
 */
function openCtlInfPop(url) {
	var LeftPosition = (screen.width-794) / 2;
	var TopPosition = (screen.height-462) / 2;
	var popObj = window.open(url,'lsDevCtlPop', 'scrollbars=yes,toolbar=no,resizable=yes,status=no,location=yes,menubar=no,width=800,height=700,top='+TopPosition+',left='+LeftPosition);
	
	openLinkPop(popObj);
}


var joRegul = {ordinSeq : ""
	 ,joNo : ""
	 ,joBrNo : ""
	 ,datClsCd : ""
	};

/* ê·ì  ìì¹ë²ê·ì ì ë³´ ì ê³µì ìí function */  
function joRegulatedPop(ordinSeq, joNo, joBrNo){
	
	var action = "";
	if(ordinSeq == null){
		ordinSeq = el('ordinSeq').value;
		joNo = "";
		joBrNo = "";
	}
	
	joRegul.ordinSeq = ordinSeq;
	joRegul.joNo = joNo;
	joRegul.joBrNo = joBrNo;		
	
	
	action = "ordinJoRegulatedList.do";

	joRegulateAjax(action,makeParam(joRegul));
}	

function joRegulateAjax(urlName,parameter){
	$.ajax({
		url: urlName,
		type:'POST',
		dataType:'text',
		data: parameter,
		timeout : 150000,
		success: function(responseData, result){
			var popLeft = (window.document.body.clientWidth - 690) / 2;
			$("#regLinkLayer").html(responseData).show();
			$("#regOrdin").css('left',popLeft);
			$("#regOrdin").draggable({handle : ".regOrdin_header" , containment : ".viewwrap"});
		}
	});
}

function joRegulatedPopClose(){
	$('.regOrdin').fadeOut(200);
	$('a[href^="#regOrdin-"].active').focus().removeClass('active');
}

/**
 * ë²ë ¹ ìëë§í¬(íìë§í¬)ë¥¼ ìí function
 * í´ë¹ í¨í´ì ë§í¬ë°ì´í°ê° ìëì§ ì²´í¬íì¬ ìì¼ë©´ 'íìë²ë ¹ ìì' ë ì´ì´ë¥¼ ë³´ì¬ì¤ë¤. 
 * fncLawPopìì ë¶ë¦¬íì¬ ì¬ì©
 * @see fncLawPop, RegexpUtil.createLsPttnLinkInfo
 * @param LSPTTNINF_SEQ (ë²ë ¹í¨í´ì ë³´ ì¼ë ¨ë²í¸)
 * @returns all (ì ì²´ì¡°ë¬¸)- infoR í¸ì¶
 *            , jo (íìë²ë ¹ íì)
 *            , ne (ìë´ ë ì´ì´)
 *   <pre>
 *   ââââââââââââââââââ
 *   â ì í âììë²âíìë²âíìëª©ë¡â
 *   ââââââââââââââââââ¤
 *   â  A        O         O         O     â
 *   â  B        O         O          X     â
 *   â  C        O         X          X     â
 *   ââââââââââââââââââ
 *       jo = A
 *       ne = C
 *       all = A + B, B (Bë§ ë³´ì¬ì¤ë¤.)
 *   </pre>
 *   @author ì¤ì ê· 
 */
function fncLsPttnLinkPop(lspttninfSeq){
	$.ajax({
		method: "POST",
		type: "POST",
		url: "lsPttnLinkChk.do",
		dataType:'text',
		data : {lspttninfSeq : lspttninfSeq},
		timeout : 10000,
		success:function(data){
			if("jo" == data){
				var url = "lsLinkCommonInfo.do?lspttninfSeq=" + lspttninfSeq + "&chrClsCd=" + getValue("lsBdyChrCls");
				var size = "width=798, height=681, status=no, toolbar=no, resizable=no, scrollbars=no, menubar=no";
				
				//íìì°½ ì¤ë³µ ì´ë¦¼ ë°©ì§
				window.open(url, '', size);
			} else if("ne" == data){
				var divId = 'joTempDeleLayer';
				lnkTitle = "<div class=\"towp2\" style=\"width:614px;\"><DIV class=ltit2 style=\"width:550px;\" id=\"tmpLtit2Link\">ë²ë ¹</DIV>"
					+"<div class=\"btn22\" style=\"float:right;\">"
					+"<A href=\"#AJAX\" onclick=\"javascript:TempJoDeleLayer.hiddenTempLsLinkLayer();return false;\"><IMG class=maJoHst alt=ë«ê¸° src=\"/LSW/images/button/btn_close8.gif\">"
					+"</A></DIV></div>";
				
				$("#"+divId).html("<div class=\"vwrap4\" style=\"left:0px;height:300px; width: 620px;\" id=\"contwrapLinkDiv\">"+
									"<div class=\"viewla11\" style=\"width: 614px; border-top:1px solid #ffffff;margin-top:-6px; height: 110px;\" id=\"viewLinkDiv\">"+
									"<div style=\"width:590px; height:95px; padding:10px 15px; font-family: Gulim,doutm,tahoma,sans-serif; font-size: 1.1em;\">"+
									"<div class=\"insd\" style=\"height:88px;overflow-x:hidden;overflow-y:hidden;margin-top:-1px;margin-left:0px;margin-right:0px;margin-bottom:0px\" id=\"tmpLsLinkDiv\">"+
									"<p style=\"float:left;padding:0; margin:15px 0 0 0\"><img src=\"/LSW/images/icon_alert_cirm2.gif\"></p>"+
									"<ul style=\"float:right;width:550px;\">"+
									"<li style=\"line-height:170%; padding: 0 10px 10px 10px;\"><b>ì¡°ë¬¸ìì ììí ì¬í­ì ê·ì í íìë²ë ¹ì´ ììµëë¤.</b>"+
									"<br>* ìì¸í ì¬í­ì ìê´ë¶ì²ì ë¬¸ìíìê¸° ë°ëëë¤.</li>"+
									"</ul>"+
									"</div>"+
									"</div>"+
									"</div>"+
									"</div>");
				
				TempJoDeleLayer.showTempLsLinkLayer(0,lnkTitle);
			} else if("all" == data){
				
			}
		},
		error : function(x, t, m) {
			if(t == "timeout"){
				alert("ì¬ì©ëì´ ë§ì ìëµì´ ì§ì° ììµëë¤ ì ì í ë¤ì ì¬ì©íìê¸° ë°ëëë¤.");
			}
		}
	});
}

/**
 * ë²ë ¹ ìëë§í¬ìì íìë²ë ¹ì´ ììë íì¤íëë ë©ì¸ì§ ë ì´ì´
 */
var TempJoDeleLayer = (function() {
	
	var joTempDeleLayer = null;
	
	return {
		
		showTempLsLinkLayer : function(size, title) {
			
			if (size == 0) {
				
				if (LsLinkLayer.returnLsLinkLayer()) {
					LsLinkLayer.hiddenLsLinkLayer();
				}
				if (joTempDeleLayer) {
					joTempDeleLayer.dialog('close');
				}
				
				joTempDeleLayer = $("#joTempDeleLayer").dialog({
					autoScroll:false
					,title: title
					,width: 620
					,height: 145
					,modal : false
					,resizable : false
					,position : {
						// ë´ ê°ì²´ ìì¹
						my : 'center',
						// ì°¸ì¡°í  ê°ì²´ ìì¹
						at : 'center',
						// ì°¸ì¡°í  ê°ì²´ ì§ì 
						of : $('body')
					}
				});
				
				joTempDeleLayer.dialog("open");
			}
		}
		,hiddenTempLsLinkLayer : function(){
			if(joTempDeleLayer){
				joTempDeleLayer.dialog('close');
			}
		}
		,returnJoTempDeleLayer : function() {
			return joTempDeleLayer;
		}
	};
}());

function lsLinkCommonPrint(para) {
    var para = para;
    var url = "";
    
    if (!para) {
    	para = document.location.href.split("?").pop();
    	para = decodeURIComponent(para);
    }

	if (para.indexOf("lsJoLnkSeq") > -1 || para.indexOf("lspttninfSeq") > -1) {
		url = "lsLinkCommonInfo.do?"+para+"&print=print";
	}else if(para.indexOf("ordinlnkpttnSeq") > -1){
		url = "ordinLinkPttnPop.do?"+para+"&print=print";
	}
	openPrintPop(url,"ë§í¬ì ë³´ì¶ë ¥");
}
