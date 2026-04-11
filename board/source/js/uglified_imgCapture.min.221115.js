var IC={canvasWrap:document.getElementsByClassName("wrap")[0],canvasCover:document.getElementsByClassName("canvas_cover")[0],
canvasScroll:document.getElementsByClassName("canvas_scroll")[0],tools:document.getElementById("tools"),
canvases:{image:document.getElementById("canvas_image"),top:document.getElementById("canvas_top")},orgImg:null
};IC.core=function(){var a,b,c,d,e,f,g,h,i=$("nav.tool_box"),j=i.find("#tools button"),k=!1,l=!1,m=!1,n=!1,o=!1,p=!1,q={
27:"exitEditing",33:"moveCursorUp",34:"moveCursorDown",35:"moveCursorRight",36:"moveCursorLeft",37:"moveCursorLeft",
38:"moveCursorUp",39:"moveCursorRight",40:"moveCursorDown"},r={textLayer:!1,shapeLayer:!1,drawLayer:!1
},s={selection:!1,isDrawingMode:!1,renderOnAddRemove:!1},t=function(){if(f=IC.canvases,canvasImage=f.image,
canvasTop=f.top,ctxImage=canvasImage.getContext("2d"),ctxTop=canvasTop.getContext("2d"),b=$(".add_to_mail"),
c=$(".save_to_pc"),e=$("input#chk_resize_scale"),d=i.find("button[data-name='resizeLayer']"),$(window).bind("beforeunload",v),
IC.canvasWrap.addEventListener("mousedown",w,!0),j.on("mouseover",J),j.on("mouseout",K),j.on("mousedown",A),
e.on("click",A),b.on("click",P),c.on("click",M),IC.resize.init(),IC.history.init(),IC.loader.init(),
IC.rotation.init(),IC.crop.init(),IC.text.init(),IC.textLayer.layerInit(),IC.shape.init(),IC.shapeLayer.layerInit(),
IC.draw.init(),IC.drawLayer.layerInit(),IC.mosaic.init(),u(),"function"!=typeof f.image.getContext("2d").setLineDash&&$(".select_line_type").hide(),
Q()&&window.opener.mwEditor.selectedImage){var a=window.opener.mwEditor.selectedImage,g=new Image;a.width&&(g.width=a.width),
a.height&&(g.height=a.height),g.onload=function(){IC.loader.fillCanvasWithImage(g)},g.src=a.src}},u=function(){
fabric.IText.prototype.keysMap=q,fabric.IText.prototype.objectCaching=!1,fabric.IText.prototype.editingBorderColor="#dddddd",
fabric.IText.prototype.fontFamily="sans-serif",fabric.IText.prototype.toolType="text",fabric.IText.prototype.padding=6,
fabric.Path.prototype.toolType="draw",fabric.Object.prototype.transparentCorners=!1,fabric.Object.prototype.cornerSize=8,
fabric.Object.prototype.borderColor="#dddddd",fabric.Object.prototype.cornerColor="#ffffff",fabric.Object.prototype.cornerStrokeColor="#222222";
},v=function(){return!k&&IC.orgImg?IC.lang[cCode].close_confirm:void 0},w=function(a){if(g){if(x())return void a.stopPropagation();
var b=$(a.target);return"crop"===g.name?void y("crop",b):void(b&&"CANVAS"!==b.prop("tagName")?"resize"===g.name?y("resize",b):"draw"===g.name?y("draw",b):"text"===g.name?y("text",b):"shape"===g.name?y("shape",b):"mosaic"===g.name&&y("mosaic",b):("move"===b.css("cursor")&&g.inactive(),
g&&W(b)&&g.inactive(),g&&"resize"===g.name&&(m||g.inactive()),fb()))}},x=function(){var a=IC.textLayer.getInputStatus(),b=IC.shapeLayer.getInputStatus(),c=IC.drawLayer.getInputStatus(),d=IC.resize.getInputStatus();
return a||b||c||d},y=function(a,b){var c=IC[a].name,d=b.data("name");if("crop"!==a){if("resize"===a){
if("resize_width"===b.attr("id")&&b.hasClass("disabled")||"resize_height"===b.attr("id")&&b.hasClass("disabled")||"LABEL"===b.prop("tagName")&&"resize_relate"===b.attr("for")||"LABEL"===b.prop("tagName")&&"resize_width"===b.attr("for")||"LABEL"===b.prop("tagName")&&"resize_height"===b.attr("for"));else if("LABEL"===b.prop("tagName")||b.hasClass("toggle_resize")||b.hasClass("current_scale")||"resize_width"===b.attr("id")||"resize_height"===b.attr("id"))return;
return void(m||g.inactive())}d&&d.indexOf(c)>-1&&"BUTTON"===b.prop("tagName")||g.inactive()}else if(b&&"CANVAS"!==b.prop("tagName")&&"navSubCropExec"!==b.attr("id")){
if(b.hasClass("current_scale")||b.hasClass("toggle_resize")||"rotation"===b.data("name")||"BUTTON"===b.prop("tagName")||"BUTTON"===b.prop("tagName")&&b.data("name")&&b.data("name").indexOf("crop")>-1)return;
g.inactive("cancel")}},z=function(a){var b=a.isLayer()&&a.getTool(),c=r[a.name];if(c)a.inactive();else{
if(I())g?g!==b&&("crop"===g.name?g.inactive("cancel"):g.inactive(),g=b,g.active("withLayer")):(g=b,g.active("withLayer")),
a.active();else{if(g){var d=g.getLayer();g.inactive(),d.inactive()}g=b;var e=g.getLayer();g.active(),
e.active()}H(a.name,!0)}},A=function(){var a=IC[$(this).data("name")];return IC.text.exitEditingText(),
i.hasClass("disabled")?void 0:"rotation"===a.name?void E(a):a.isLayer()?void z(a):(I()||B(),"resize"===a.name?void C.bind(this,a)():g===a?void D(a):void E(a));
},B=function(){for(var a in r)if(r.hasOwnProperty(a)&&r[a])return void IC[a].inactive()},C=function(b){
if(a){var c=a.getActiveObject();c&&(a.discardActiveObject(),a.renderAll())}if(this===e[0]){if(m)return void b.inactive("checkbox");
E(b,"checkbox")}else g===b?m?(b.inactive("checkbox"),b.active()):b.inactive():E(b)},D=function(a){return g.hasLayer()&&r[g.getLayer().name]&&g.getLayer().inactive(),
"crop"===a.name?void g.inactive("cancel"):void g.inactive()},E=function(a,b){var c=a.active;if("checkbox"===b&&(c=a.active.bind(null,"checkbox")),
g){if("crop"===g.name)return void g.inactive("cancel",c);g.inactive()}g=a,a.active(b)},F=function(){
return m},G=function(a){m=a},H=function(a,b){r[a]=b},I=function(){for(var a in r)if(r.hasOwnProperty(a)&&r[a])return!1;
return!0},J=function(){if(!i.hasClass("disabled")){var a=$(this);a.hasClass("selected")||a.addClass("over");
}},K=function(){$(this).removeClass("over")},L=function(a){var b=f.image,c=b.getContext("2d"),d=a?/(image|top)/:/image/;
_();for(var e in f)d.test(e)||c.drawImage(f[e],0,0);return b},M=function(){if(IC.orgImg){if(g&&g.inactive){
var a;"crop"===g.name&&(a="shouldClearCanvasTop"),g.inactive(a)}var b=L(!1),c=N()+".png",d=b.toDataURL("imgae/png");
if("undefined"!==navigator&&navigator.msSaveOrOpenBlob){for(var e=atob(d.replace(/^.*?base64,/,"")),f=new Uint8Array(e.length),h=0,i=e.length;i>h;++h)f[h]=e.charCodeAt(h);
var j=new Blob([f.buffer],{type:"imgae/png"});navigator.msSaveOrOpenBlob(j,c)}else{var k=document.getElementById("downloadLink");
k.href=window.URL.createObjectURL(O(d)),k.download=c,k.click()}}},N=function(){var a=new Date,b=(""+a.getFullYear()).substring(2,4),c=a.getMonth()+1,d=a.getDate(),e=a.getHours(),f=a.getMinutes();
return 10>c&&(c="0"+c),10>d&&(d="0"+d),10>e&&(e="0"+e),10>f&&(f="0"+f),""+b+c+d+e+f},O=function(a){for(var b=atob(a.split(",")[1]),c=[],d=0;d<b.length;d++)c.push(b.charCodeAt(d));
return new Blob([new Uint8Array(c)],{type:"image/png"})},P=function(){if(IC.orgImg){if(g&&g.inactive){
var a;"crop"===g.name&&(a="shouldClearCanvasTop"),g.inactive(a)}var b=L(!1),c=b.toDataURL("image/png");
try{Q()?window.opener.mwEditor.getEditorMode()===window.opener.mwEditor.WYSIWYG_MODE?(window.opener.mwEditor.insertImageFromImageEditor(c),
k=!0,window.close()):alert(IC.lang[cCode].WYSIWYG_only):alert(IC.lang[cCode].opener_closed)}catch(d){
alert(IC.lang[cCode].opener_closed)}}},Q=function(){return window.opener&&window.opener.mwAttach&&(window.opener.mCom.isPopup||"none"!==window.opener.document.getElementById("writeWrap").style.display);
},R=function(){return a},S=function(){return a?a:T()},T=function(){return U(),a=new fabric.Canvas("canvas_top",s),
db("top",canvasImage.width,canvasImage.height),a.on("mouse:down",function(b){var c=b.target,d=$(b.e.target);
if(c&&g){if(c.toolType!==g.name&&!a.isDrawingMode)return void g.inactive();if("text"===g.name&&IC.text.getCurrentEditingText()===c){
var e=c.getSelectionStartFromPointer(b.e);xb()&&(c.inCompositionMode=!1),c.selectionStart=e,c.selectionEnd=e,
c._fireSelectionChanged(),c._updateTextarea()}}else c||!IC.text.getCurrentEditingText()||g?c&&"text"===c.toolType&&(o=W(d)?!1:h===c?!0:!1):_();
p=!1}),a.on("mouse:up",function(b){var c=b.target,d=$(b.e.target),e=a.getActiveObject();return a.isDrawingMode?void IC.history.saveHistory():void(c&&"text"===c.toolType?(c.isEditing?c._updateTextarea():e&&"text"===e.toolType&&n&&"move"===d.css("cursor")&&h===c&&o&&!p&&(c.setCursorByClick(b.e),
c.enterEditing()),h=c,n=!0):n=!1)}),a.on("mouse:move",function(){p=!0}),a.on("object:modified",function(){
IC.history.saveHistory()}),a.on("object:removed",function(){IC.history.saveHistory()}),a.on("selection:cleared",function(){
h=null}),cb("on","rotation"),V(),a},U=function(){s.width=canvasImage.width,s.height=canvasImage.height;
},V=function(){delete s.width,delete s.height},W=function(a){var b=a.css("cursor"),c=["se-resize","ne-resize","nw-resize","sw-resize","n-resize","e-resize","w-resize","s-resize"];
return c.indexOf(b)>-1},X=function(){var b=IC.drawLayer.getConf();a.freeDrawingBrush.color=b.color,a.freeDrawingBrush.width=b.weight;
},Y=function(b,c){if(a){var d=a.getActiveObject();r.drawLayer&&X(),d&&!Z(d)&&("strokeColor"===b?d.set("stroke",c):"fillColor"===b?d.set("fill",c):"weight"===b?d.set("strokeWidth",c):"borderType"===b?d.set("strokeDashArray",c):"fontSize"===b?d.set("scaleX",1).set("scaleY",1).set("fontSize",c):"fontWeight"===b?d.set("fontWeight",c):"fontStyle"===b?d.set("fontStyle",c):"underline"===b?d.set("underline",c):"linethrough"===b&&d.set("linethrough",c),
a.renderAll())}},Z=function(a){if(g){var b=g.getLayer().name,c={shapeLayer:["rect","ellipse","line"],
textLayer:["i-text"],drawLayer:["path"]};return c[b].indexOf(a.type)<0}},_=function(){a&&(a.isDrawingMode=!1,
a.discardActiveObject(),a.renderAll())},ab=function(){a&&(a.dispose(),canvasTop.style.width="",canvasTop.style.height="",
a=null)},bb=function(){var b=a.getActiveObjects();if(b)for(var c=0,d=b.length;d>c;c++)a.remove(b[c]);
a.renderAll()},cb=function(b,c){if(a){var d={text:"url(https://ssl.pstatic.net/static/pwe/nm/cur_text.png) 5 8, text",
shape:"url(https://ssl.pstatic.net/static/pwe/nm/cur_cross.png) 10 10, crosshair",draw:"url(https://ssl.pstatic.net/static/pwe/nm/cur_draw.png) 0 22, auto",
rotation:"url(https://ssl.pstatic.net/static/pwe/nm/cur_lotation.png) 10 3, auto"};IC.core.isIE()&&(d={
text:"url(https://ssl.pstatic.net/static/pwe/nm/cur_text.cur), auto",shape:"url(https://ssl.pstatic.net/static/pwe/nm/cur_cross.cur), auto",
draw:"url(https://ssl.pstatic.net/static/pwe/nm/cur_draw.cur), auto",rotation:"url(https://ssl.pstatic.net/static/pwe/nm/cur_lotation_180305.cur), auto"
}),"on"===b?"draw"===c?a.freeDrawingCursor=d[c]:"rotation"===c?a.rotationCursor=d[c]:a.defaultCursor=d[c]:"off"===b&&("draw"===c?a.freeDrawingCursor="default":a.defaultCursor="default");
}},db=function(a,b,c){var d=f[a];d.width=b,d.height=c},eb=function(a){var b=IC.canvases[a];b.getContext("2d").clearRect(0,0,b.width,b.height);
},fb=function(){for(var a in r)if(r.hasOwnProperty(a)){var b=r[a];if(b)return IC[a].inactive(!0),void H(a,!1);
}},gb=function(){for(var a in r)if(r.hasOwnProperty(a)){var b=r[a];if(b)return!0}return!1},hb=function(a){
var b=IC.core.getFabricCanvas();if(b){var c=b.getActiveObject();c&&-1===a.indexOf(c.type)&&IC.core.deactivateObjects();
}},ib=function(a,b){IC.loader.getRemoveBtnStatus()&&("resize"===b&&d.show(),g&&g.name!==b&&nb(),jb("add",a,"selected"));
},jb=function(a,b,c){"add"===a?b.addClass(c):"remove"===a&&b.removeClass(c)},kb=function(){return g},lb=function(a){
g=a},mb=function(){g=null},nb=function(){g&&g.inactive()},ob=function(b){a||S(),a.isDrawingMode=b},pb=function(b){
var c=!1;a?(c=!0,_(),ab(),canvasTop.style.width="",canvasTop.style.height="",ctxImage.drawImage(canvasTop,0,0),
ctxTop.clearRect(0,0,canvasTop.width,canvasTop.height)):c=!1,b&&b(c)},qb=function(b){a=S(),a.loadFromJSON(b,a.renderAll.bind(a)),
canvasTop.style.width="",canvasTop.style.height="",rb()},rb=function(){var b=a._objects;b.forEach(function(a){
"i-text"===a.type&&(a.on("editing:entered",IC.text.onEditingEntered),a.on("editing:exited",IC.text.onEditingExited));
})},sb=function(a){l=a},tb=function(a,b){b.css({left:a.offset().left})},ub=function(a){mouseDownedTarget=a;
},vb=function(a){var b=0;if(("text"===a.type||"tel"===a.type)&&document.selection){a.focus();var c=document.selection.createRange();
c.moveStart("character",-a.value.length),b=c.text.length}return b},wb=function(a,b){if("text"===a.type||"tel"===a.type)if(a.setSelectionRange)a.focus(),
a.setSelectionRange(b,b);else if(a.createTextRange){var c=a.createTextRange();c.collapse(!0),c.moveEnd("character",b),
c.moveStart("character",b),c.select()}},xb=function(){return!1||!!document.documentMode};return{init:t,
mergeAll:L,getActiveTool:kb,setActiveTool:lb,resetActiveTool:mb,setMouseCursor:cb,getFabricCanvas:R,
loadFabricCanvas:S,setDrawStyle:X,updateActiveObject:Y,deactivateObjects:_,clearCanvas:eb,removeFabricCanvas:ab,
removeActiveObjects:bb,setCanvasSize:db,hideAllLayer:fb,toolMouseDownHandler:ib,setLayerState:H,isAnyLayerOpened:gb,
deselectInvalidObject:hb,disableEnabledTool:nb,setToolIconStatus:jb,getResizeCheckBox:F,setResizeCheckBox:G,
setFreeDrawingMode:ob,compressCanvases:pb,decompressCanvases:qb,setIsImageLoaded:sb,adjustLayerOffset:tb,
setMouseDownedTarget:ub,getCaretPosition:vb,setCaretPosition:wb,isIE:xb}}(),IC=IC||{},IC.lang={ko_KR:{
default_text1:"PC에 저장된 이미지를 불러오거나",default_text2:"마우스로 끌어와 편집할 수 있습니다.",only_one_image_allowed:"한 개의 이미지 파일만 사용 가능합니다. ",
reset_image:"적용된 효과가 모두 사라지며, 되돌릴 수 없습니다. 초기화하시겠습니까?",remove_image:"편집효과는 물론 이미지 전체가 삭제됩니다. 모두 삭제하시겠습니까?",
cut_image:"자르기",only_image_allowed:"이미지 파일만 선택가능합니다.",new_image_alert:"편집 중인 이미지가 삭제됩니다. 새로운 이미지를 불러오시겠습니까?",
text_no_newline:"텍스트 입력은 1줄만 가능합니다.",opener_closed:"메일쓰기창이 닫혀 메일 본문에 이미지를 삽입할 수 없습니다.\n'PC에 저장'을 누르시면 로컬에 저장하실 수 있습니다.",
close_confirm:"이미지 편집을 종료하시겠습니까?",WYSIWYG_only:"이미지 삽입은 에디터 모드에서만 가능합니다.",maximum_dimension:"이미지 편집에서 이미지는 최대 2000px로 불러옵니다.",
limit_upload_size:"이미지 편집에는 20MB 미만의 이미지 파일만 불러올 수 있습니다."},ja_JP:{default_text1:"PCに保存された画像を読みこんだり",
default_text2:"マウスのドラッグ操作で貼り付けて編集することができます。",only_one_image_allowed:"1つの画像ファイルのみ使用できます。",reset_image:"画像の編集内容は適用されず読み込み時の状態に戻ります。よろしいですか？",
remove_image:"編集効果だけでなくイメージも削除されます。 削除しますか?",cut_image:"切り取り",only_image_allowed:"画像ファイルのみ選択できます。",new_image_alert:"編集中の画像が削除されます。新しい画像を読み込みますか。",
text_no_newline:"テキストは1行のみ入力できます。",opener_closed:"メール作成画面が閉じているため、メールの本文に画像を挿入できません。「PCに保存」を押してローカルに保存することができます。",
close_confirm:"画像の編集を終了しますか。",WYSIWYG_only:"イメージはエディタモードにおいてのみ挿入そうにゅうされます。",maximum_dimension:"編集可能な最大画素数2000pxに変更し画像を読み込みます。",
limit_upload_size:"20MBを超える画像は編集できません。"},en_US:{default_text1:"Drag an image here or",default_text2:"upload from PC to edit and insert.",
only_one_image_allowed:"Only one image file can be used.",reset_image:"All applied effects will disappear and cannot be restored. Initialize?",
remove_image:"Editing effects as well as images will be deleted. Delete all?",cut_image:"Crop",only_image_allowed:"Please select image files only.",
new_image_alert:"The edited image will be deleted. Import a new image?",text_no_newline:"Enter only one line in the text.",
opener_closed:"Unable to insert an image in the mail text because the mail compose window has been closed. Press 'Save in PC' to save the image in the local directory.",
close_confirm:"Exit the image editing? ",WYSIWYG_only:"Images can be inserted only in editor mode.",
maximum_dimension:"Up to 2000px sized image can be loaded in Edit Image.",limit_upload_size:"Only image files of 20MB and below can be loaded in Edit Image."
},zh_CN:{default_text1:"可以通过读取或者鼠标拖拽PC里的图片，",default_text2:"对其进行编辑。",only_one_image_allowed:"只能使用一个图像文件。",
reset_image:"适用的效果都将被删除，不能恢复。确定要初始化吗？",remove_image:"编辑效果会随着图片一起删除。确认要全部删除吗?",cut_image:"裁剪",only_image_allowed:"只能选择图片",
new_image_alert:"删除了正在编辑中的图片。是否需要打开新的图片?",text_no_newline:"文本只能输入一行。",opener_closed:"写邮件的对话框已被关闭，故不能插入图像。点击“保存至PC”，就可把图像保存至电脑。",
close_confirm:"是否完成对图片的编辑？",WYSIWYG_only:"图片插入功能只有在编辑模式下进行。",maximum_dimension:"在编辑图片中可读取2000px的图片。",
limit_upload_size:"在编辑图片中仅可读取小于20MB的图片文件。"},zh_TW:{default_text1:"可以通過讀取或者鼠標拖拽PC里的圖片，",default_text2:"對其進行編輯。",
only_one_image_allowed:"只能使用一個圖像檔案。",reset_image:"適用的效果都將被刪除，無法恢復。確定要初始化嗎？",remove_image:"編輯效果會隨著圖片一起刪除。確認要全部刪除嗎？",
cut_image:"裁剪",only_image_allowed:"只能選擇圖片",new_image_alert:"刪除了正在編輯中的圖片。是否需要打開新的圖片?",text_no_newline:"文本只可輸入一行。",
opener_closed:"寫郵件的對話框已被關閉，故無法插入圖像。點擊“儲存至PC”，即可把圖像儲存至電腦。",close_confirm:"是否完成對圖片的編輯？",WYSIWYG_only:"圖片插入功能只有在編輯模式下進行。",
maximum_dimension:"圖片編輯中讀取圖片最大為2000px。",limit_upload_size:"圖片編輯中僅可讀取20MB以下圖檔。"}},IC=IC||{},IC.loader=function(){
function a(a){var b,c=a.target,d=c.value;""!==d&&(b=c.files[0],b&&v(b)),$("#localFile").detach(),h.parent().append('<input id="localFile" type="file" style="visibility:hidden;width:0;height:0;">');
}var b,c,d,e,f,g,h,i=!1,j=!1,k=20*Math.pow(2,20),l=2e3,m=(-1===navigator.userAgent.indexOf("Chrome")&&/safari|applewebkit/i.test(window.navigator.userAgent),
/Macintosh/.test(navigator.userAgent),function(){b=document.getElementsByClassName("canvas_scroll")[0],
c=IC.canvases,d=$(".indicator"),h=$("#pasteCatcher"),g=$(".button_area"),$(window).on("resize",p),n(),
p(),h.focus()}),n=function(){$(window).on("dragover",z),$(window).on("drop",A),$(".btn_fileload").on("click",o),
$(document.body).on("paste",t),$(window).on("click",q)},o=function(){$("#localFile").on("change",a),
$("#localFile").click()},p=function(){if(!IC.orgImg){var a=c.image,d=a.getContext("2d"),e=[IC.lang[cCode].default_text1,IC.lang[cCode].default_text2];
a.style.display="none",a.width=b.clientWidth,a.height=b.clientHeight,a.style.display="",d.font="ja_JP"===cCode?"bold 16px Meiryo":"bold 16px 'Malgun Gothic', '맑은 고딕', '나눔고딕', 'Apple SD Gothic Neo', '돋움', 'Dotum', 'Helvetica', 'Sans-serif'",
d.fillstyle="#2d3545",d.textAlign="center",d.textBaseline="middle",d.globalAlpha=.3,d.fillText(e[0],a.width/2,a.height/2-15),
d.fillText(e[1],a.width/2,a.height/2+15)}},q=function(a){a&&"INPUT"===a.target.tagName||IC.core.getActiveTool()===IC.text||h.focus();
},r=function(a,b){var c;(!IC.orgImg||confirm(IC.lang[cCode].new_image_alert))&&(s(),$(".tool_box").removeClass("disabled"),
g.find(".save_to_pc").removeAttr("disabled"),g.find(".add_to_mail").removeAttr("disabled"),$("#chk_resize_scale").removeAttr("disabled"),
IC.core.setIsImageLoaded(!0),f=f||$(".action .remove button"),j||(f.on("click",I),j=!0),f.removeClass("disabled"),
f.removeAttr("disabled"),"string"==typeof a?(c=new Image,c.onload=function(){E(c),"function"==typeof b&&b();
},c.src=a):(E(a),"function"==typeof b&&b()))},s=function(){var a=IC.core.getActiveTool();a&&a.inactive(!0),
IC.history.resetStorages()},t=function(a){var b,c,e,f=a.originalEvent;if(b=f.clipboardData?f.clipboardData:window.clipboardData,
b&&b.items&&(c=b.items.length),"undefined"!=typeof b&&c>0)for(var g=0;c>g;g++){var h=b.items[g];if(h.type.indexOf("image")>-1){
var i=h.getAsFile();if(i.size>=k)return void alert(IC.lang[cCode].limit_upload_size);e=new FileReader,
e.onload=function(a){var b=a.target.result;r(b)},e.readAsDataURL(i),d.find(".filename").hide()}}else setTimeout(u,20);
},u=function(){var a=h[0],b=a.getElementsByTagName("img")[0],c=/(read\/image\/original|write\/image\/path)/;
if(a.innerHTML="",b){if(c.test(b.src))return void r(b.src);d.find(".filename").hide(),/^https?:\/\//.test(b.src)||r(b.src);
}},v=function(a){var b=new RegExp(/^image\//i),c=a,d=b.test(c.type);return d?c.size>=k?void alert(IC.lang[cCode].limit_upload_size):void("image/tiff"===c.type?w(c):x(c)):void alert(IC.lang[cCode].only_image_allowed);
},w=function(a){var b=new FileReader;b.onload=function(){var c=new Tiff({buffer:b.result}).toDataURL();
r(c,y.bind(null,a.name))},b.readAsArrayBuffer(a)},x=function(a){var b=new FileReader;b.onload=function(b){
var c=b.target.result;r(c,y.bind(null,a.name))},b.readAsDataURL(a)},y=function(a){var b=d.find(".filename");
if(a){var c=a.lastIndexOf(".");b.attr("title",a),b.find(".part_name").text(a.substr(0,c)),b.find(".part_ext").html(a.slice(c)),
b.show()}else b.hide()},z=function(a){var b;b=a.originalEvent,b.stopPropagation(),b.preventDefault();
},A=function(a){var b=a.originalEvent;b.stopPropagation(),b.preventDefault();var c=b.dataTransfer&&b.dataTransfer.files;
c&&(1!==c.length?alert(IC.lang[cCode].only_one_image_allowed):v(c[0]))},B=function(){i||(e=e||$(".action .reset button"),
e.on("click",D),e.removeAttr("disabled"),e.removeClass("disabled"),i=!0)},C=function(){i&&(e=e||$(".action .reset button"),
e.off("click",D),e.attr("disabled","disabled"),e.addClass("disabled"),i=!1)},D=function(){i&&confirm(IC.lang[cCode].reset_image)&&(E(IC.orgImg),
e.off("click",D),e.attr("disabled","disabled"),e.addClass("disabled"),i=!1,IC.core.disableEnabledTool());
},E=function(a){F(a)&&(alert(IC.lang[cCode].maximum_dimension),a=G(a)),IC.orgImg=a,d.find(".nagative_msg").hide(),
s();var b=d.find(".dimension"),f=a.width,g=a.height;b.text(f+" x "+g+" (px)"),b.show(),IC.resize.setUI(f,g,100),
IC.resize.clearAllCanvas(f,g),c.image.getContext("2d").drawImage(a,0,0,a.naturalWidth,a.naturalHeight,0,0,f,g),
IC.core.removeFabricCanvas(),IC.history.saveHistory(),e&&(e.off("click",D),e.attr("disabled","disabled"),
e.addClass("disabled"),i=!1)},F=function(a){return a.width>l||a.height>l},G=function(a){var b=H(a);return a.width=b.width,
a.height=b.height,a},H=function(a){var b,c,d=a.width>=a.height?"width":"height";return"width"===d?(b=l,
c=b*a.height/a.width):(c=l,b=c*a.width/a.height),{width:b,height:c}},I=function(a){if(a===!0||confirm(IC.lang[cCode].remove_image)){
IC.history.resetStorages();var b=IC.core.getActiveTool();b&&b.inactive(!0),f.addClass("disabled"),f.attr("disabled","disabled"),
f.off("click",I),j=!1,i=!1,IC.resize.clearAllCanvas(0,0),IC.core.removeFabricCanvas(),IC.orgImg=null,
p(),d.find(".dimension").hide(),d.find(".filename").hide(),d.find(".nagative_msg").show(),$(".tool_box").addClass("disabled"),
g.find(".save_to_pc").attr("disabled","disabled"),g.find(".add_to_mail").attr("disabled","disabled"),
$("#chk_resize_scale").attr("disabled","disabled")}},J=function(){return j};return{init:m,showNotice:p,
enableReset:B,disableReset:C,removeImage:I,getRemoveBtnStatus:J,fillCanvasWithImage:r}}(),IC=IC||{},
IC.resize=function(){var a,b,c,d,e,f,g,h,i,j,k,l=!1,m=5,n=2e3,o=27,p=13,q=100,r={width:0,height:0,scale:100
},s=function(){b=IC.canvases,a=document.getElementsByClassName("canvas_scroll")[0],c={width:a.offsetWidth,
height:a.offsetHeight},h=$(".layers .ly_resize"),j=$("#resize_relate"),d=$("#chk_resize_scale"),i=$(IC.tools).find("button[data-name='resize']"),
f=$("LI.resize").find("input:text"),f.on("focus",G),f.on("blur",H),f.on("keydown",H),h.on("click","LI",u);
},t=function(a){"checkbox"==a?(IC.core.setResizeCheckBox(!0),IC.core.setToolIconStatus("remove",i,"selected"),
h.hide(),F()):(IC.core.setToolIconStatus("add",i,"selected"),h.show()),IC.core.setActiveTool(IC.resize),
$(".resize .current_scale").html(r.scale||"100%"),q=q||100,(100==q||IC.mosaic.getIsMosaicAdded())&&(z(),
IC.mosaic.getIsMosaicAdded()&&IC.mosaic.setIsMosaicAdded(!1))},u=function(){var a=parseInt($(this).data("scale"),10);
return a===q?void L():void w(v(a))},v=function(a){var c=!1,d=b.image.width,e=b.image.height,f=a,g=d*(f/q),h=e*(f/q),i=d/e;
return g>n&&(g=n,h=g/i,c=!0),h>n&&(h=n,g=h*i,c=!0),c&&J(),{width:Math.round(g),height:Math.round(h),
scale:f}},w=function(a){var b=a.width,c=a.height,d=a.scale;x(b,c),K(b,c,d)},x=function(a,d){IC.core.compressCanvases(y),
c=c||{},c.width=a,c.height=d;for(var e in b)b.hasOwnProperty(e)&&A(b[e],c);B(c)},y=function(a){a&&(k=b.image.toDataURL("image/png"));
},z=function(){k=b.image.toDataURL("image/png")},A=function(a,b){a.width=b.width,a.height=b.height},B=function(a){
var b=new Image;b.onload=C.bind(null,b,a),b.src=k},C=function(a,c){var d=b.image.getContext("2d");d.drawImage(a,0,0,c.width,c.height),
IC.history.saveHistory()},D=function(a,d){c=c||{},c.width=a,c.height=d;for(var e in b)b.hasOwnProperty(e)&&(b[e].width=a,
b[e].height=d);K(a,d,100)},E=function(){return c},F=function(){e=e||$("#tools").find(".toggle_resize"),
e.toggleClass("disabled"),j.prop("disabled")?(j.prop("disabled",!1).prop("checked",!0),$("label[for='resize_relate']").removeClass("disabled")):(j.prop("disabled",!0).prop("checked",!1),
$("label[for='resize_relate']").addClass("disabled")),f.prop("disabled",!f.prop("disabled"))},G=function(a){
a.stopPropagation(),l=!0,this.value=parseInt(this.value,10),IC.core.isIE()&&IC.core.setCaretPosition(this,this.value.length);
},H=function(a){a.stopPropagation();var b=this.id.replace("resize_","");{if("keydown"!==a.type){if(l=!1,
isNaN(this.value)||""===this.value)return void(this.value=r[b]);var c=parseInt(this.value,10);return c===parseInt(r[b],10)?void(f["width"===b?0:1].value=r[b]):void w(I(b,c));
}if(a.keyCode===p)this.blur();else if(a.keyCode===o)return void(this.value=r[b])}},I=function(a,c){var d,e,f=b.image.width,g=b.image.height,h=f/g,i=100,k=j.prop("checked"),l=!1;
return"width"===a?(d=c,m>d?d=m:d>n&&(d=n,l=!0),k?(e=d/h,e>n?(e=n,l=!0):m>e&&(e=m),d=e*h):e=g):"height"===a&&(e=c,
m>e?e=m:e>n&&(e=n,l=!0),k?(d=e*h,d>n?(d=n,l=!0):m>d&&(d=m),e=d/h):d=f),l&&J(),{width:Math.round(d),height:Math.round(e),
scale:i}},J=function(){var a;switch(cCode){case"en_US":a="Zoom in/out can be up to 2000px in Edit image.";
break;case"ja_JP":a="画像編集の際、拡大・縮小は最大2000pxまで可能です。";break;case"zh_CN":a="在图像编辑中支持扩大/缩小最大到2000px。";break;
case"zh_TW":a="於圖像編輯放大/縮小時，支援至2000px。";break;default:a="이미지 편집에서 확대/축소는 최대 2000px까지 지원합니다."}alert(a);
},K=function(a,b,c){f[0].value=r.width=a+"px",f[1].value=r.height=b+"px",r.scale=c+"%",$(".resize .current_scale").html(r.scale),
a+b===0&&(f[1].value=f[0].value=""),q!==c&&($("ul.resize_preset").find("li[data-scale='"+q+"']").removeClass("selected"),
$("ul.resize_preset").find("li[data-scale='"+c+"']").addClass("selected"),q=c),h.is(":visible")&&L(),
g=$(".indicator").find(".dimension"),g.text(a+" x "+b+" (px)"),g.show()},L=function(a){"checkbox"==a||IC.core.getResizeCheckBox()?(d.prop("checked",!1),
IC.core.setResizeCheckBox(!1),F()):(IC.core.setToolIconStatus("remove",i,"selected"),h.hide()),IC.core.resetActiveTool();
},M=function(){return l},N=function(){return!1},O=function(){return!1},P=function(){return h};return{
name:"resize",init:s,clearAllCanvas:D,getSize:E,setUI:K,active:t,inactive:L,getInputStatus:M,isLayer:N,
hasLayer:O,getLayer:P}}(),IC=IC||{},IC.rotation=function(){var a,b,c,d=-90,e=Math.PI/180,f=function(){
a=IC.canvases,canvasImage=a.image,canvasTop=a.top,b=$(IC.tools).find("button[data-name='rotation']"),
$btnUndo=$("ul.action .undo button"),$btnRedo=$("ul.action .redo button"),b.on("mousedown",IC.core.toolMouseDownHandler.bind(null,b,"rotation"));
},g=function(){IC.core.compressCanvases(h)},h=function(){i(),j(),l()},i=function(){c=canvasImage.toDataURL();
},j=function(){for(var b in a)a.hasOwnProperty(b)&&k(a[b])},k=function(a){var b=a.width;a.width=a.height,
a.height=b},l=function(){var a=new Image,b=c;a.onload=m.bind(null,a),a.src=b},m=function(a){var c=canvasImage.getContext("2d"),f=canvasImage.width,g=canvasImage.height;
c.clearRect(0,0,f,g),c.save(),c.translate(f/2,g/2),c.rotate(d*e),c.drawImage(a,-g/2,-f/2),c.restore(),
IC.history.saveHistory(),IC.resize.setUI(f,g,100),IC.core.setToolIconStatus("remove",b,"selected disabled"),
IC.core.resetActiveTool()},n=function(){return!1},o=function(){return!1};return{name:"rotation",init:f,
active:g,isLayer:n,hasLayer:o,rotateCanvas:k}}(),IC=IC||{},IC.crop=function(){var a,b,c,d,e,f,g,h,i=3,j=function(){
canvasImage=IC.canvases.image,a=IC.canvases.top,ctxImage=canvasImage.getContext("2d"),b=a.getContext("2d"),
c=$(IC.tools).find("button[data-name='crop']"),d={x1:0,y1:0,x2:0,y2:0,mouseOpt:{startX:0,startY:0}}},k=function(){
e=!1,f=!1,g=!1,IC.history.saveBuffer(),IC.core.compressCanvases(),IC.core.setToolIconStatus("add",c,"selected"),
IC.canvasCover.classList.add("cursor_cross"),h=h||$("#navSubCropExec"),$(a).on("mousedown",z),h.on("click",y),
l(),b.save(),u(),b.restore()},l=function(){b.strokeStyle="#1cbe4d",b.lineWidth=2,"function"==typeof b.setLineDash&&b.setLineDash([]);
},m=function(b,c){var d,e={};return d=a.getBoundingClientRect(),e.x=b-d.left,e.y=c-d.top,e.x>d.width?e.x=d.width:e.x<0&&(e.x=0),
e.y>d.height?e.y=d.height:e.y<0&&(e.y=0),e},n=function(){var a;return d.x1>d.x2&&(a=d.x1,d.x1=d.x2,d.x2=a),
d.y1>d.y2&&(a=d.y1,d.y1=d.y2,d.y2=a),d},o=function(){{var b,c,e,f,g;IC.canvases}b=d.x1,c=d.y1,e=d.x2-d.x1,
f=d.y2-d.y1,0>b?(e+=b,b=0):a.width-b<e&&(e=a.width-b),0>c?(f+=c,c=0):a.height-c<f&&(f=a.height-c),g={
x:b,y:c,w:e,h:f},p(canvasImage,g),r(),C()},p=function(a,b){var c=new Image;c.src=a.toDataURL("image/png"),
c.onload=q.bind(null,a,c,b)},q=function(b,c,d){var e=d.x,f=d.y,g=d.w,h=d.h;b.width=g,b.height=h;var i=b.getContext("2d");
i.drawImage(c,e,f,g,h,0,0,g,h),a.width=g,a.height=h,IC.resize.setUI(Math.round(g),Math.round(h),100),
IC.history.saveHistory()},r=function(){b.clearRect(0,0,a.width,a.height),IC.core.removeFabricCanvas(),
d.x1=0,d.y1=0,d.x2=0,d.y2=0,l(),w()},s=function(a,c,d,e){var f,g;f=d-a,g=e-c,b.rect(a,c,f,g)},t=function(c,d,e,f){
b.save(),b.clearRect(0,0,a.width,a.height),u(),b.globalCompositeOperation="destination-out",b.beginPath(),
s(c,d,e,f),b.closePath(),b.globalAlpha=1,b.fill(),b.restore(),b.beginPath(),s(c,d,e,f),b.closePath(),
b.stroke()},u=function(){b.beginPath(),b.rect(0,0,a.width,a.height),b.fillStyle="black",b.globalAlpha=.35,
b.fill()},v=function(){var a,b,c,e,f;a=d.x2,b=d.y2,c=d.x2-d.x1,e=d.y2-d.y1,h=h||$("#navSubCropExec"),
h.text(Math.round(c)+"x"+Math.round(e)+" "+IC.lang[cCode].cut_image),f=h.width(),f+=parseInt(h.css("padding-left"),10)+parseInt(h.css("padding-right"),10),
f+=parseInt(h.css("margin-left"),10)+parseInt(h.css("margin-right"),10),f+=parseInt(h.css("borderLeftWidth"),10)+parseInt(h.css("borderRightWidth"),10),
h.css({left:Math.max(0,a-f+1)+"px",top:b+"px"}),h.show()},w=function(){h.hide()},x=function(){var a=n();
t(a.x1,a.y1,a.x2,a.y2),v()},y=function(){e&&(f=!0,h.hide(),o())},z=function(a){var b=m(a.clientX,a.clientY);
d.x1=b.x,d.y1=b.y,$(document).on("mousemove",A),$(document).on("mouseup",B),w()},A=function(a){var b=m(a.clientX,a.clientY);
t(d.x1,d.y1,b.x,b.y),d.x2=b.x,d.y2=b.y,g=!0},B=function(a){var c=m(a.clientX,a.clientY),f=Math.abs(d.x2-d.x1)>i&&Math.abs(d.y2-d.y1)>i;
g&&f?(x(c),e=!0,g=!1):(r(),b.save(),u(),b.restore()),$(document).off("mousemove",A),$(document).off("mouseup",B);
},C=function(b,d){h.off("click",y),$(a).off("mousedown",z),$(document).off("mousemove",A),$(document).off("mouseup",B),
IC.canvasCover.classList.remove("cursor_cross"),IC.core.setToolIconStatus("remove",c,"selected"),IC.core.resetActiveTool(),
r(b),"cancel"===b&&IC.history.loadBuffer(d)},D=function(){return!1},E=function(){return!1};return{name:"crop",
init:j,active:k,inactive:C,isLayer:D,hasLayer:E}}(),IC=IC||{},IC.shape=function(){var a,b,c,d,e,f,g=!1,h=function(){
b=$(IC.tools).find("button[data-name='shape']")},i=function(c){"withLayer"!==c&&IC.core.deactivateObjects(),
a=IC.core.loadFabricCanvas(),a.on("mouse:down",j),a.on("mouse:move",m),a.on("mouse:up",o),IC.core.setActiveTool(IC.shape),
IC.core.setMouseCursor("on","shape"),IC.core.setToolIconStatus("add",b,"selected")},j=function(b){if(!b.target){
g=!0,IC.core.setMouseDownedTarget(b.target);var d=a.getPointer(b.e),e=d.x,f=d.y;k(e,f),c=l(e,f),a.add(c);
}},k=function(a,b){e=a,f=b},l=function(a,b){d=IC.shapeLayer.getConf();var c=d.type;return"rect"===c?new fabric.Rect({
left:a,top:b,width:0,height:0,strokeWidth:d.weight,stroke:d.strokeColor,fill:d.fillColor,strokeDashArray:d.borderType,
selectable:!0,toolType:"shape"}):"round"===c?new fabric.Rect({left:a,top:b,width:0,height:0,strokeWidth:d.weight,
stroke:d.strokeColor,fill:d.fillColor,strokeDashArray:d.borderType,selectable:!0,rx:10,ry:10,toolType:"shape"
}):"circle"===c?new fabric.Ellipse({left:a,top:b,rx:1,ry:1,strokeWidth:d.weight,stroke:d.strokeColor,
strokeDashArray:d.borderType,fill:d.fillColor,toolType:"shape"}):"line"===c?new fabric.Line([a,b,a,b],{
originX:"center",originY:"center",stroke:d.strokeColor,strokeWidth:d.weight,strokeDashArray:d.borderType,
toolType:"shape"}):void 0},m=function(b){if(g){var c=a.getPointer(b.e);n(d.type,c)}},n=function(b,d){
var g=(IC.shapeLayer.getConf(),d.x),h=d.y;"rect"===b||"round"===b?(e>g&&c.set("left",g),f>h&&c.set("top",h),
c.set({width:Math.abs(e-g),height:Math.abs(f-h)})):"circle"===b?(e>g&&c.set("left",g),f>h&&c.set("top",h),
c.set({rx:.5*Math.abs(e-g),ry:.5*Math.abs(f-h)})):"line"===b&&c.set({x2:g,y2:h}),c.setCoords(),a.renderAll();
},o=function(b){var d=a.getPointer(b.e),e=d.x,f=d.y;p(e,f)?IC.history.saveHistory():a.remove(c),g=!1,
c=null,q()},p=function(a,b){return e+f-a-b!==0},q=function(){a.off("mouse:down",j),a.off("mouse:move",m),
a.off("mouse:up",o),IC.core.setMouseCursor("off","shape"),IC.core.setToolIconStatus("remove",b,"selected"),
IC.core.resetActiveTool(),IC.shapeLayer.inactive()},r=function(){return!1},s=function(){return!0},t=function(){
return IC.shapeLayer};return{name:"shape",init:h,active:i,inactive:q,isLayer:r,hasLayer:s,getLayer:t
}}(),IC=IC||{},IC.draw=function(){var a,b=function(){a=$(IC.tools).find("button[data-name='draw']")},c=function(b){
"withLayer"!=b?IC.core.deactivateObjects():IC.core.deselectInvalidObject(["path"]),IC.core.setFreeDrawingMode(!0),
IC.core.setActiveTool(IC.draw),IC.core.setMouseCursor("on","draw"),IC.core.setToolIconStatus("add",a,"selected"),
IC.core.setDrawStyle()},d=function(){IC.core.setFreeDrawingMode(!1),IC.core.setMouseCursor("off","draw"),
IC.core.setToolIconStatus("remove",a,"selected"),IC.core.resetActiveTool(),IC.drawLayer.inactive()},e=function(){
return!1},f=function(){return!1},g=function(){return IC.drawLayer};return{name:"draw",init:b,active:c,
inactive:d,isLayer:e,hasLayer:f,getLayer:g}}(),IC=IC||{},IC.text=function(){var a,b,c,d,e,f,g,h,i,j,k=!1,l=!1,m=function(){
a=IC.canvases.top,b=a.getContext("2d"),d=$(".ly_text"),c=$("#input_text"),e=$(IC.tools).find("button[data-name='text']"),
n()},n=function(){j=IC.textLayer.getConf()},o=function(a){"withLayer"!==a&&IC.core.deactivateObjects(),
l=!0,f=IC.core.loadFabricCanvas(),f.on("mouse:down",s),IC.core.setActiveTool(IC.text),IC.core.setMouseCursor("on","text"),
IC.core.setToolIconStatus("add",e,"selected"),n()},p=function(){var a=new fabric.IText("",{fill:j.color,
fontSize:j.size,fontWeight:j.fontWeight,fontStyle:j.fontStyle,underline:j.underline,linethrough:j.linethrough
});return a.on("editing:entered",q),a.on("editing:exited",r),a},q=function(){k=!0,h=this,this.inCompositionMode=!1,
this._updateTextarea();var a=$("textarea").last();a.attr("id","hidden_textarea"),a.css("position","fixed"),
a.css("width","0px"),a.css("height","0px"),IC.core.setActiveTool(IC.text),""===this.text&&IC.core.isIE()&&setTimeout(function(){
a.select()},50)},r=function(){l=!1,k=!1,h=null,IC.core.setMouseCursor("off","text"),IC.core.resetActiveTool(),
f.off("mouse:down",s)},s=function(a){if(a.target){var b=IC.core.getActiveTool();return void(b&&"text"===b.name&&(IC.core.setMouseCursor("off","text"),
IC.core.setToolIconStatus("remove",e,"selected")))}var c=p();c.left=a.e.offsetX,c.top=a.e.offsetY,c.inCompositionMode=!1,
f.add(c).setActiveObject(c),c.enterEditing(),IC.core.setToolIconStatus("remove",e,"selected"),IC.core.setMouseDownedTarget(a.target),
IC.textLayer.inactive(),x(c)},t=function(){f.off("mouse:down",s),IC.core.setMouseCursor("off","text"),
IC.core.setToolIconStatus("remove",e,"selected"),IC.core.resetActiveTool(),z(),IC.textLayer.inactive();
},u=function(){$(".canvas-container").length&&f.dispose()},v=function(){return h},w=function(){return g;
},x=function(a){g=a},y=function(){return k},z=function(){k&&h.exitEditing()},A=function(){return!1},B=function(){
return!0},C=function(){return IC.textLayer};return{name:"text",init:m,active:o,inactive:t,isLayer:A,
hasLayer:B,getLayer:C,onInsertTextStart:s,getLastSelectedText:w,setLastSelectedText:x,isTextEditing:y,
exitTextEditing:i,deleteObjects:u,exitEditingText:z,onEditingEntered:q,onEditingExited:r,getCurrentEditingText:v
}}(),IC=IC||{},IC.mosaic=function(){function a(){q=$(IC.tools).find("button[data-name='mosaic']"),s=t.getContext("2d"),
r=IC.canvases.top}function b(){IC.core.compressCanvases(),IC.core.setToolIconStatus("add",q,"selected"),
IC.canvasCover.classList.add("cursor_pixelated"),IC.core.setActiveTool(IC.mosaic),$(r).on("mousedown",d);
}function c(){IC.canvasCover.classList.remove("cursor_pixelated"),$(r).off("mousedown",d),IC.core.setToolIconStatus("remove",q,"selected"),
IC.core.resetActiveTool()}function d(a){var b=l(a.clientX,a.clientY);y.oldX=b.x,y.oldY=b.y,w=!0,h(y.oldX,y.oldY,u),
$(r).on("mousemove",e),$(document).on("mouseup",g)}function e(a){if(w){var b=l(a.clientX,a.clientY);f(b.x,b.y)&&(y.oldX+=Math.round((b.x-y.oldX)/u)*u,
y.oldY+=Math.round((b.y-y.oldY)/u)*u,h(y.oldX,y.oldY,u))}}function f(a,b){return(a-y.oldX)*(a-y.oldX)+(b-y.oldY)*(b-y.oldY)>=Math.pow(u,2);
}function g(){w&&(w=!1,IC.history.saveHistory(),$(r).off("mousemove",e),$(document).off("mouseup",g));
}function h(a,b,c){for(var d=s.getImageData(a,b,c,c),e=d.width,f=d.height,g=e/v,h=f/v,j=0;h>j;j++)for(var l=0;g>l;l++){
var m=v,n=0,o=v,p=0;(l+1)*v+a>t.width?m=t.width-a-l*v:0>a&&0===l&&(n=-a),(j+1)*v+b>t.height?o=t.height-b-j*v:0>b&&0===j&&(p=-b);
for(var q=i(d,l*v+n,j*v+p,l*v+n+m,j*v+p+o),r=0;v>r;r++)for(var u=0;v>u;u++)k(d,l*v+u,j*v+r,q)}s.putImageData(d,a,b),
x||(x=!0)}function i(a,b,c,d,e){for(var f=(d-b)*(e-c),g=0,h=0,i=0,k=0,l=b;d>l;l++)for(var m=c;e>m;m++){
var n=j(a,l,m);g+=n[0],h+=n[1],i+=n[2],k+=n[3]}return[g/f,h/f,i/f,k/f]}function j(a,b,c){var d=a.width,e=[];
return e[0]=a.data[4*(c*d+b)],e[1]=a.data[4*(c*d+b)+1],e[2]=a.data[4*(c*d+b)+2],e[3]=a.data[4*(c*d+b)+3],
e}function k(a,b,c,d){var e=a.width;a.data[4*(c*e+b)]=d[0],a.data[4*(c*e+b)+1]=d[1],a.data[4*(c*e+b)+2]=d[2],
a.data[4*(c*e+b)+3]=d[3]}function l(a,b){var c={x:0,y:0},d=r.getBoundingClientRect();return c.x=a-d.left-u/2,
c.y=b-d.top-u/2,c.x+u>d.width?c.x=d.width-u:c.x<0&&(c.x=0),c.y+u>d.height?c.y=d.height-u:c.y<0&&(c.y=0),
c}function m(){return!1}function n(){return!1}function o(){return x}function p(a){x=a}var q,r,s,t=IC.canvases.image,u=20,v=10,w=!1,x=!1,y={
oldX:0,oldY:0};return{name:"mosaic",init:a,active:b,inactive:c,isLayer:m,hasLayer:n,getIsMosaicAdded:o,
setIsMosaicAdded:p}}(),IC=IC||{},IC.shapeLayer=function(){var a,b,c,d,e,f,g,h,i,j,k=50,l=1,m=!1,n=!1,o=["rect","ellipse","line"],p={
type:"rect",weight:2,borderType:[],strokeColor:"#ff0000",fillColor:null,radius:2},q=function(){a=$(".ly_shape"),
c=a.find(".select_shape .current"),d=a.find(".size_control input.range_base"),e=a.find("#line_thickness"),
f=a.find(".select_line_type .current"),g=a.find(".color_picker_preset"),h=a.find(".select_shape .select_horizon"),
i=a.find(".select_line_type .select_vertical"),j=a.find('label[for="shape_color_type2"]'),h.find("button").on("click",G),
i.find("button").on("click",Q),d.on("change",J),e.on("focus",H),e.on("keydown",I),e.on("blur",N),a.find(".size_control .range_decrease").on("click",P),
a.find(".size_control .range_increase").on("click",O),a.find(".shape_color_type input").on("click",R),
g.find("button").on("click",S),$toolBtn=$(IC.tools).find("button[data-name='shape']"),b=$(IC.tools).find("button[data-name='shapeLayer']"),
a.css("z-index","30"),a.find(".btn_colorpicker").on("click",function(){var b=a.find(".shape_color_type input:checked").data("type");
IC.colorPicker.show(X.bind(IC.shapeLayer,b))}),IC.core.adjustLayerOffset($toolBtn,a)},r=function(){IC.core.deselectInvalidObject(o),
IC.core.setToolIconStatus("add",b,"selected"),s()},s=function(){var b=IC.core.getFabricCanvas();if(b){
var c=b.getActiveObject();c?t(c):"line"===p.type?w():F()}a.show()},t=function(a){u(a.type)&&(v(a),x(a.strokeDashArray),
L(a.strokeWidth,["range","input"]),T(a.type,a.stroke,a.fill),B())},u=function(a){return o.indexOf(a)>-1;
},v=function(a){var b=a.type,d="rect"===b&&a.rx>0&&a.ry>0;d?b="round":"ellipse"===b&&(b="circle"),"line"===b&&w();
var e=y(b);c.html(e.children().clone()),p.type=b},w=function(){var b=a.find(".shape_color_type input:checked").data("type");
"fill"===b&&E(),n=!0,j.hide()},x=function(a){var b=z(a);f.html(b.children().clone()),p.borderType=a},y=function(a){
return $("ul.select_horizon").find("[data-type='"+a+"']")},z=function(a){var b=A(a);return $("ul.select_vertical").find("[data-type='"+b+"']");
},A=function(a){return 0===a.length?"solid":3===a[0]?"dotted":7===a[0]?"dashed":void 0},B=function(){
$(".select_horizon").find("button").addClass("disabled").prop("disabled",!0)},C=function(){return m?void(m=!1):(D(),
E(),F(),IC.core.setToolIconStatus("remove",b,"selected"),IC.core.setLayerState("shapeLayer",!1),void a.hide());
},D=function(){$(".select_horizon").find("button").removeClass("disabled").prop("disabled",!1)},E=function(){
var a=p.strokeColor,b=g.find(".selected");b.removeClass("selected");var c=g.find("button[data-color="+a+"]");
c.length>0&&c.parent().addClass("selected"),$("#shape_color_type1").prop("checked",!0),$("#shpae_color_type2").prop("checked",!1);
},F=function(){n&&(j.show(),n=!1)},G=function(){var a=$(this),b=h.find(".selected");b.removeClass("selected"),
a.addClass("selected"),c.html(a.children().clone()),p.type=this.getAttribute("data-type"),"line"==p.type?w():F();
},H=function(a){a.stopPropagation(),m=!0,this.value=parseInt(this.value,10),IC.core.isIE()&&IC.core.setCaretPosition(this,this.value.length);
},I=function(a){13===a.keyCode&&this.blur()},J=function(){var a=K(parseInt(this.value,10));L(a,["range","input"]);
},K=function(a){var b=a;return isNaN(b)&&(b=p.weight),b>k?b=k:l>b&&(b=l),b},L=function(a,b){var c=K(a);
M(c,b),IC.core.updateActiveObject("weight",c)},M=function(a,b){p.weight=a,b.indexOf("range")>-1&&(d.get(0).value=a),
b.indexOf("input")>-1&&(e.get(0).value=a+"px")},N=function(){m=!1;var a=parseInt(this.value,10);L(a,["range","input"]);
},O=function(){L(p.weight+1,["range","input"])},P=function(){L(p.weight-1,["range","input"])},Q=function(){
var a=$(this),b=i.find(".selected");switch(b.removeClass("selected"),a.addClass("selected"),f.html(a.children().clone()),
this.getAttribute("data-type")){case"dashed":p.borderType=[7,7];break;case"dotted":p.borderType=[3,3];
break;case"solid":p.borderType=[]}IC.core.updateActiveObject("borderType",p.borderType)},R=function(){
var a=this.getAttribute("data-type"),b=p[a+"Color"],c=g.find(".selected");c.removeClass("selected");var d=g.find("button[data-color="+b+"]");
d.length>0&&d.parent().addClass("selected")},S=function(){var b=a.find(".shape_color_type input:checked").data("type"),c="null"!==this.getAttribute("data-color")?this.getAttribute("data-color"):null;
U(b,c,this)},T=function(a,b,c){U("stroke",b),"line"!==a&&U("fill",c)},U=function(b,c,d){V(b,c);var e=a.find(".shape_color_type input:checked").data("type");
e===b&&W(b,c,d)},V=function(a,b){p[a+"Color"]=b},W=function(a,b,c){c||(c=g.find('[data-color="'+b+'"]')),
g.find("li.selected").removeClass("selected"),$(c).parent().addClass("selected"),IC.core.updateActiveObject(a+"Color",b);
},X=function(a,b){b&&/(stroke|fill)/.test(a)&&(p[a+"Color"]=b,IC.core.updateActiveObject(a+"Color",b),
g.find("li.selected").removeClass("selected"))},Y=function(){return m},Z=function(){return p},_=function(){
return!0},ab=function(){return IC.shape};return{name:"shapeLayer",layerInit:q,active:r,inactive:C,getConf:Z,
isLayer:_,getTool:ab,getInputStatus:Y}}(),IC=IC||{},IC.drawLayer=function(){var a,b,c,d,e,f,g=50,h=1,i=!1,j=["path"],k={
weight:2,color:"#ff0000"},l=function(){a=$(".ly_draw"),b=a.find(".size_control input.range_base"),c=a.find("#line_thickness_draw"),
d=a.find(".color_picker_preset"),e=a.find(".btn_colorpicker"),b.on("change",y),c.on("focus",w),c.on("keydown",x),
c.on("blur",C),a.css("z-index","30"),a.find(".size_control .range_decrease").on("click",F),a.find(".size_control .range_increase").on("click",E),
d.find("button").on("click",r),e.on("click",IC.colorPicker.show.bind(null,m)),$toolBtn=$(IC.tools).find("button[data-name='draw']"),
f=$(IC.tools).find("button[data-name='drawLayer']"),IC.core.adjustLayerOffset($toolBtn,a)},m=function(a){
a&&(k.color=a,IC.core.updateActiveObject("strokeColor",a),d.find("li.selected").removeClass("selected"));
},n=function(){IC.core.deselectInvalidObject(["path"]),IC.core.setToolIconStatus("add",f,"selected"),
o()},o=function(){var b=IC.core.getFabricCanvas();if(b){var c=b.getActiveObject();c&&p(c)}a.show(),IC.core.loadFabricCanvas().renderAll();
},p=function(a){q(a.type)&&(A(a.strokeWidth,["range","input"]),s(a.stroke))},q=function(a){return j.indexOf(a)>-1;
},r=function(){var a="null"!==this.getAttribute("data-color")?this.getAttribute("data-color"):null;s(a,this);
},s=function(a,b){t(a),u(a,b)},t=function(a){k.color=a},u=function(a,b){b||(b=d.find('[data-color="'+a+'"]')),
d.find("li.selected").removeClass("selected"),$(b).parent().addClass("selected"),IC.core.updateActiveObject("strokeColor",a);
},v=function(){return i?void(i=!1):(IC.core.setToolIconStatus("remove",f,"selected"),IC.core.setLayerState("drawLayer",!1),
void a.hide())},w=function(a){a.stopPropagation(),i=!0,this.value=parseInt(this.value,10),IC.core.isIE()&&IC.core.setCaretPosition(this,this.value.length);
},x=function(a){13===a.keyCode&&this.blur()},y=function(){var a=z(parseInt(this.value,10));A(a,["range","input"]);
},z=function(a){var b=a;return isNaN(b)&&(b=k.weight),b>g?b=g:h>b&&(b=h),b},A=function(a,b){var c=z(a);
B(c,b),IC.core.updateActiveObject("weight",c)},B=function(a,d){k.weight=a,d.indexOf("range")>-1&&(b.get(0).value=a),
d.indexOf("input")>-1&&(c.get(0).value=a+"px")},C=function(){i=!1;var a=parseInt(this.value,10);D(this.value)||""===this.value||this.blur(),
A(a,["range","input"])},D=function(a){var b=parseInt(a,10);return!isNaN(a)&&g>=b&&b>=h},E=function(){
A(k.weight+1,["range","input"])},F=function(){A(k.weight-1,["range","input"])},G=function(){return i;
},H=function(){return k},I=function(){return!0},J=function(){return IC.draw};return{name:"drawLayer",
layerInit:l,active:n,inactive:v,getConf:H,isLayer:I,getTool:J,getInputStatus:G}}(),IC=IC||{},IC.textLayer=function(){
var a,b,c,d,e,f,g,h,i=50,j=10,k="Auto",l=!1,m=!1,n=["i-text"],o={size:15,color:"#ff0000",fontWeight:"normal",
fontStyle:"normal",underline:!1,linethrough:!1},p=function(){a=$(".ly_text"),c=a.find(".range_cover"),
d=a.find(".size_control input.range_base"),e=a.find("#font_size_input"),g=a.find(".select_line_type .current"),
$styleButtons=a.find(".text_style"),f=a.find(".color_picker_preset"),h={fontWeight:$styleButtons.find(".text_bold"),
fontStyle:$styleButtons.find(".text_italic"),underline:$styleButtons.find(".text_underline"),linethrough:$styleButtons.find(".text_strike")
},d.on("change",F),e.on("focus",D),e.on("keydown",E),e.on("blur",J),a.find(".size_control .range_increase").on("click",K),
a.find(".size_control .range_decrease").on("click",L),h.fontWeight.on("click",M("fontWeight")),h.fontStyle.on("click",M("fontStyle")),
h.underline.on("click",M("underline")),h.linethrough.on("click",M("linethrough")),f.find("button").on("click",q),
$toolBtn=$(IC.tools).find("button[data-name='text']"),b=$(IC.tools).find("button[data-name='textLayer']"),
a.find(".btn_colorpicker").on("click",function(){IC.colorPicker.show(P)}),IC.core.adjustLayerOffset($toolBtn,a);
},q=function(){var a="null"!==this.getAttribute("data-color")?this.getAttribute("data-color"):null;z(a,this);
},r=function(){IC.core.deselectInvalidObject(["i-text"]),IC.core.setToolIconStatus("add",b,"selected"),
w(),s()},s=function(){var b=IC.core.getFabricCanvas();if(b){var c=b.getActiveObject();c&&t(c)}a.show(),
IC.core.loadFabricCanvas().renderAll()},t=function(a){u(a.type)&&(v(a.fontSize,a.scaleX,a.scaleY),z(a.fill),
y(a.fontWeight,a.fontStyle,a.underline,a.linethrough))},u=function(a){return n.indexOf(a)>-1},v=function(a,b,c){
var d=1!==b||1!==c;d?x():H(a,["range","input"])},w=function(){var a=o.size;e.get(0).value=a+"px",e.removeClass("disabled"),
d.get(0).value=a,d.removeClass("disabled"),c.css({"pointer-events":""})},x=function(){e.get(0).value=k,
e.addClass("disabled"),d.addClass("disabled"),c.css({"pointer-events":"none"})},y=function(a,b,c,d){
o.fontWeight=a,o.fontStyle=b,o.underline=c,o.linethrough=d,O()},z=function(a,b){A(a),B(a,b)},A=function(a){
o.color=a},B=function(a,b){b||(b=f.find('[data-color="'+a+'"]')),f.find("li.selected").removeClass("selected"),
$(b).parent().addClass("selected"),IC.core.updateActiveObject("fillColor",a)},C=function(){return l?void(l=!1):(IC.core.setToolIconStatus("remove",b,"selected"),
IC.core.setLayerState("textLayer",!1),void a.hide())},D=function(a){return a.stopPropagation(),l=!0,
this.value===k?(this.value="",e.removeClass("disabled"),void(m=!0)):(this.value=parseInt(this.value,10),
void(IC.core.isIE()&&IC.core.setCaretPosition(this,this.value.length)))},E=function(a){13===a.keyCode&&this.blur();
},F=function(){var a=G(parseInt(this.value,10));H(a,["range","input"])},G=function(a){var b=a;return isNaN(b)&&(b=o.size),
b>i?b=i:j>b&&(b=j),b},H=function(a,b){var c=G(a);I(c,b),IC.core.updateActiveObject("fontSize",c)},I=function(a,b){
o.size=a,b.indexOf("range")>-1&&(d.get(0).value=a),b.indexOf("input")>-1&&(e.get(0).value=a+"px")},J=function(){
l=!1;var a=this.value,b=parseInt(a,10);if(m){if(m=!1,""===a)return this.value=k,void e.addClass("disabled");
d.removeClass("disabled"),c.css({"pointer-events":""})}H(b,["range","input"])},K=function(){H(o.size+1,["range","input"]);
},L=function(){H(o.size-1,["range","input"])},M=function(a){return function(){N(a),IC.core.updateActiveObject(a,o[a]);
}},N=function(a){"fontWeight"===a?o.fontWeight="normal"===o.fontWeight?"bold":"normal":"fontStyle"===a?o.fontStyle="normal"===o.fontStyle?"italic":"normal":"underline"===a?o.underline=o.underline===!1?!0:!1:"linethrough"===a&&(o.linethrough=o.linethrough===!1?!0:!1),
O()},O=function(){var a=["fontWeight","fontStyle"],b=["underline","linethrough"];a.forEach(function(a){
var b=h[a];"normal"===o[a]?b.removeClass("selected"):b.addClass("selected")}),b.forEach(function(a){
var b=h[a];o[a]?b.addClass("selected"):b.removeClass("selected")})},P=function(a){a&&(o.color=a,IC.core.updateActiveObject("fillColor",a),
f.find("li.selected").removeClass("selected"))},Q=function(){return l},R=function(){return o},S=function(){
return!0},T=function(){return IC.text};return{name:"textLayer",layerInit:p,active:r,inactive:C,getConf:R,
isLayer:S,getTool:T,getInputStatus:Q}}(),IC=IC||{},IC.colorPicker=function(){var a,b,c,d,e,f,g,h,i,j,k=!1,l=function(){
f=$(".ly_text_picker"),g=f.find("#cpcode"),h=f.find(".cp-panel-pointer"),i=f.find(".cp-preview"),b=f.find(".cp-panel-color canvas")[0],
c=f.find(".cp-panel-hue canvas")[0],d=b.getContext("2d"),e=c.getContext("2d"),j=r(0,100,100),$(b).on("mousedown",t),
$(c).on("mousedown",w),f.find(".btn_close").on("click",A),f.find(".cp-submit").on("click",B),m(),n(),
k=!0,z()},m=function(){var a,c=b.width,e=b.height;a=d.createLinearGradient(0,0,c,0),a.addColorStop(0,"rgba(255,255,255,1)"),
a.addColorStop(1,"rgba(255,255,255,0)"),d.fillStyle=a,d.fillRect(0,0,c,e),a=d.createLinearGradient(0,0,0,e),
a.addColorStop(0,"rgba(0,0,0,0)"),a.addColorStop(1,"rgba(0,0,0,1)"),d.fillStyle=a,d.fillRect(0,0,c,e);
},n=function(){for(var a,b=e.createLinearGradient(0,0,c.width,0),d=0;7>d;d++)a=q(d/6*360,100,100),b.addColorStop(d/6,"rgb("+a.join(",")+")");
e.fillStyle=b,e.fillRect(0,0,c.width,c.height)},o=function(a){var c,d,e,f=b.width,k=b.height,l=1,m=1,n=q(j.h,100,100);
b.style.background="#"+p(n[0],n[1],n[2]),a?h.css({left:a.offsetX+"px",top:a.offsetY+"px"}):(c=j.s/100*f,
d=(100-j.v)/100*k,c=Math.max(Math.min(c-1,f-l),1),d=Math.max(Math.min(d-1,k-m),1),h.css({left:c+"px",
top:d+"px"})),n=q(j.h,j.s,j.v),e="#"+p(n[0],n[1],n[2]),i.css("background-color",e),g.val(e)},p=function(a,b,c){
return a=a.toString(16),1==a.length&&(a="0"+a),b=b.toString(16),1==b.length&&(b="0"+b),c=c.toString(16),
1==c.length&&(c="0"+c),a+b+c},q=function(a,b,c){a=a%360/60,b/=100,c/=100;var d=0,e=0,f=0,g=Math.floor(a),h=a-g,i=c*(1-b),j=c*(1-b*h),k=c*(1-b*(1-h));
switch(g){case 0:d=c,e=k,f=i;break;case 1:d=j,e=c,f=i;break;case 2:d=i,e=c,f=k;break;case 3:d=i,e=j,
f=c;break;case 4:d=k,e=i,f=c;break;case 5:d=c,e=i,f=j;break;case 6:}return d=Math.floor(255*d),e=Math.floor(255*e),
f=Math.floor(255*f),s(d,e,f)},r=function(a,b,c){var d=[a,b,c];return d.h=a,d.s=b,d.v=c,d},s=function(a,b,c){
var d=[a,b,c];return d.r=a,d.g=b,d.b=c,d},p=function(a,b,c){return a=a.toString(16),1==a.length&&(a="0"+a),
b=b.toString(16),1==b.length&&(b="0"+b),c=c.toString(16),1==c.length&&(c="0"+c),a+b+c},t=function(a){
return 1!==a.which?!1:($(document).on("mouseup",u),$(document).on("mousemove",v),void v(a))},u=function(){
$(document).off("mouseup",u),$(document).off("mousemove",v)},v=function(a){if(a.target!==b)return a.preventDefault(),
a.stopPropagation(),!1;var c=a.offsetX,d=a.offsetY,e=b.width,f=b.height;c=Math.max(Math.min(c,e),0),
d=Math.max(Math.min(d,f),0),j.s=j[1]=c/e*100,j.v=j[2]=(f-d)/f*100,o(a),a.preventDefault(),a.stopPropagation();
},w=function(a){return 1!==a.which?!1:($(document).on("mouseup",x),$(document).on("mousemove",y),void y(a));
},x=function(){$(document).off("mouseup",x),$(document).off("mousemove",y)},y=function(a){var b,d;b=a.offsetX,
d=c.width,j.h=j[0]=Math.min(Math.max(b,0),d)/d*360%360,o(),a.preventDefault(),a.stopPropagation()},z=function(b){
return"function"==typeof b&&(a=b),k?void f.show():void l()},A=function(){f.hide(),a&&a()},B=function(){
var b=q(j[0],j[1],j[2]),c="#"+p(b[0],b[1],b[2]);a(c),f.hide()};return{show:z,hide:A,apply:B}}(),IC=IC||{},
IC.history=function(){function a(){canvasImage=IC.canvases.image,h=IC.canvases.top,ctxImage=canvasImage.getContext("2d"),
ctxTop=h.getContext("2d"),f=$("ul.action .undo button"),g=$("ul.action .redo button"),f.on("mouseup",H.bind(null,"undo")),
f.on("mousedown",function(){f.addClass("disabled")}),g.on("mouseup",H.bind(null,"redo")),g.on("mousedown",function(){
g.addClass("disabled")}),$(window).on("keydown",e)}function b(){var a=u.length;y.history<1?(f.addClass("disabled"),
f.attr("disabled","disabled")):f.hasClass("disabled")&&y.history>0&&(f.removeClass("disabled"),f.removeAttr("disabled")),
y.history>=a-1?(g.addClass("disabled"),g.attr("disabled","disabled")):g.hasClass("disabled")&&y.history<a-1&&(g.removeClass("disabled"),
g.removeAttr("disabled")),y.history>0&&IC.loader.enableReset()}function c(){var a=IC.core.getActiveTool();
a&&a.active()}function d(){w.static.length=w.interactive.length=u.length=0,y.history=-1,y.static=-1,
y.interactive=-1,b()}function e(a){var b=a.metaKey||a.ctrlKey;b?N(a):P(a)}var f,g,h,i=89,j=90,k=67,l=86,m=27,n=46,o=8,p=37,q=38,r=39,s=40,t=13,u=[],v={
copiedObject:null},w={"static":[],interactive:[]},x={type:null,imageObj:null,staticCursor:null,width:null,
height:null},y={"static":-1,interactive:-1,history:-1},z=function(a){A()&&"crop"!==a&&(C(),B()),D(),
u.push(F()),G("history",1),("crop"!==a||1!==y.history)&&b()},A=function(){return y.history<u.length-1;
},B=function(){for(var a=u.length-1,b=y.history,c=0;a-b>c;c++)U()},C=function(){var a=u[y.history];y.static=a.staticCanvas,
y.interactive=a.interactiveCanvas},D=function(){var a=E();G(a,1),w[a][y[a]]="static"===a?canvasImage.toDataURL():JSON.stringify(IC.core.getFabricCanvas());
},E=function(){return IC.core.getFabricCanvas()?"interactive":"static"},F=function(){return{type:E(),
staticCanvas:y.static,interactiveCanvas:y.interactive,width:canvasImage.width,height:canvasImage.height
}},G=function(a,b){y[a]+=b},H=function(a,b){if(!M(a)){var c=IC.core.getActiveTool();c&&("crop"===c.name?c.inactive("onLoadButton"):c.inactive()),
IC.text.exitEditingText(),G("history","undo"===a?-1:1),y.history<0&&(y.history=0),I(),J(b)}},I=function(){
var a=u[y.history];y.static=a.staticCanvas,y.interactive=a.interactiveCanvas},J=function(a){var b=u[y.history],c=w.static[b.staticCanvas],d=new Image;
d.onload=function(){L(b,d,a)},d.src=c},K=function(a,b,c){a.forEach(function(a){{var d=IC.canvases[a];
d.getContext("2d")}IC.core.setCanvasSize(a,b,c)})},L=function(a,d,e){var f=a.width,g=a.height;if(K(["image","top"],f,g),
"interactive"===a.type){var h=w.interactive[a.interactiveCanvas];IC.core.decompressCanvases(h)}else IC.core.removeFabricCanvas();
ctxImage.drawImage(d,0,0),IC.resize.setUI(f,g,100),b(),"function"==typeof e?e():c(),V()},M=function(a){
return"undo"===a&&0===y.history||"redo"===a&&y.history===u.length-1},N=function(a){var b=a.keyCode,c=IC.core.getFabricCanvas(),d=a.target.id;
if(j===b){if(IC.text.isTextEditing()||IC.textLayer.getInputStatus()||IC.shapeLayer.getInputStatus()||IC.drawLayer.getInputStatus())return;
a.preventDefault(),H("undo")}else if(i===b){if(-1!==window.navigator.userAgent.indexOf("Mac")&&a.preventDefault(),
IC.text.isTextEditing()||T(d))return;H("redo")}else if(k===b){if(T(d))return;O(c,v)}else l===b&&v.copiedObject&&(S(c,v),
z())},O=function(a,b){if(a){var c=a.getActiveObject();c&&c.clone(function(a){b.copiedObject=a})}},P=function(a){
var b=IC.core.getFabricCanvas(),c=a.keyCode,d=a.target.id;if(m===c){var e=IC.core.getActiveTool(),f=$(".ly_text_picker").is(":visible");
if(f)IC.colorPicker.hide();else if(e&&e.inactive)IC.core.deactivateObjects(),"crop"===e.name?e.inactive("cancel"):e.inactive(),
IC.core.isAnyLayerOpened()&&IC.core.hideAllLayer();else if(b){var g=b.getActiveObject();g&&(b.discardActiveObject(),
b.renderAll())}}else if(o===c||n===c){if(IC.text.isTextEditing()||T(d))return;IC.core.removeActiveObjects();
}else if(p===c)Q("left",a);else if(r===c)Q("right",a);else if(q===c)Q("up",a);else if(s===c)Q("down",a);else if(t===c){
if(IC.text.isTextEditing()||T(d))return;if(b){var g=b.getActiveObject();g&&"i-text"===g.type&&(a.preventDefault(),
g.enterEditing(),g.selectAll())}}},Q=function(a,b){var c=IC.core.getFabricCanvas(),d=b.target.id;if(!T(d)&&c){
var e=c.getActiveObject();if(e){var f=R(a,e);e.set(f.type,f.position),e.setCoords(),c.renderAll(),z();
}}},R=function(a,b){var c=1;switch(a){case"left":return{type:"left",position:b.left-c};case"right":return{
type:"left",position:b.left+c};case"up":return{type:"top",position:b.top-c};case"down":return{type:"top",
position:b.top+c}}},S=function(a,b){a&&(b.copiedObject.clone(function(b){b.set({left:b.left+10,top:b.top+10
}),"i-text"===b.type&&(b.on("editing:entered",IC.text.onEditingEntered),b.on("editing:exited",IC.text.onEditingExited)),
IC.core.deactivateObjects(),a.add(b),a.setActiveObject(b),a.renderAll()}),b.copiedObject.set({left:b.copiedObject.left+10,
top:b.copiedObject.top+10}))},T=function(a){var b=["resize_width","resize_height","line_thickness","font_size_input","line_thickness_draw","hidden_textarea"];
return b.indexOf(a)>-1},U=function(){y.history===u.length-1&&G("history",-1),u.pop(),b()},V=function(){
x.type=E(),x.imageObj=JSON.stringify(IC.core.getFabricCanvas()),x.staticCursor=y.static,x.width=canvasImage.width,
x.height=canvasImage.height},W=function(a){var c=w.static[x.staticCursor],d=new Image;d.onload=function(){
var c=x.width,e=x.height;K(["image","top"],c,e),"interactive"===x.type?IC.core.decompressCanvases(x.imageObj):IC.core.removeFabricCanvas(),
ctxImage.drawImage(d,0,0),IC.resize.setUI(c,e,100),b(),"function"==typeof a&&a(),x.imageObj=null,x.staticCursor=null;
},d.src=c};return{init:a,saveHistory:z,loadHistory:H,resetStorages:d,removeLastHistory:U,saveBuffer:V,
loadBuffer:W}}();