(function(){"use strict";const w=window.jQuery;class k{static init(o,e){this.$table=o,this.settings=e,this.bindEvents(o,e)}static bindEvents(o,e){this.bindVariationChange(o),this.bindQuantity(o),this.bindAddToCart(o,e)}static bindEventsFor(o,e){this.bindEvents(o,e)}static getNewUrl(o,e,n){const i=new URL(o);return i.searchParams.set("item_id",e),i.searchParams.set("quantity",n),i.toString()}static bindVariationChange(o){o.on("change",".nt_fct_variations_select",function(e){const n=w(this),i=n.find(":selected"),r=n.closest("tr"),s=n.closest(".nt_fct_variations_wrapper").data("product_id"),h=i.data("variation_id")||0,m=i.data("formatted_price")||"",v=i.data("compared_price")||"",j=i.data("image_src")||"",$=i.data("stock")||0,C=n.closest(".nt_fct_variations_wrapper").data("product_type")||"",x=r.data("is_compare_row")||!1,a=r.data("row_id")||!1,t=l=>{const u=r.find(l);return u.length?u:r.parent().find(l)};let c=t(".nt_fct_product_price_wrapper");x&&(c=t('[data-pid="'+s+'"] .nt_fct_product_price_wrapper')),c.find(".nt_fct_product_price").text(m);const _=c.find(".nt-fct-compared-price");v?_.text(v).show():_.hide();let d=t(".fct_product_image");a?d=t(`[data-row_id="${r.data("row_id")}"] .fct_product_image`):x&&(d=t(`[data-pid="${s}"] .fct_product_image`)),j&&d&&d.attr("src",j);let p=t(".nt_fct_product_stock");x&&(p=t('[data-pid="'+s+'"] .nt_fct_product_stock')),p.length&&p.attr("data-product_stock",$).text($);let f=t(`.nt_fct_add_to_cart_${s}`);if(f.attr("data-variation_id",h),C==="digital"){f=t(`.nt_fct_buy_now_${s}`),f.attr("data-variation_id",h);const l=r.find(".nt_fct_add_cart_wrapper a"),u=l.data("quantity")||1,y=k.getNewUrl(l.attr("href"),h,u);l.attr("href",y)}})}static bindQuantity(o){o.on("change",".nt_fct_quantity",function(){const e=w(this),n=e.data("product_type"),i=e.data("product_id"),r=Math.max(1,parseInt(e.val(),10)||1);e.val(r);let s=o.find(`.nt_fct_add_to_cart_${i}`);n==="digital"&&(s=o.find(`.nt_fct_buy_now_${i}`)),s.attr("data-quantity",r);const h=s.data("variation_id");if(n==="digital"){const m=k.getNewUrl(s.attr("href"),h,r);s.attr("href",m)}})}static bindAddToCart(o,e){o.on("click",'[class*="nt_fct_add_to_cart_"]:not(.loading)',function(n){var m;n.preventDefault();const i=w(this),r=i.parent();if(i.data("processing"))return;i.data("processing",!0),r.addClass("loading"),i.prop("disabled",!0);const s=(m=n.target)==null?void 0:m.dataset;if(!s)return;const h={product_id:s.product_id,variation_id:parseInt(s.variation_id),quantity:s.quantity};k._ajaxAdd([h],i,r,e)})}static _ajaxAdd(o,e,n,i){const{ajax_url:r,ninja_table_public_nonce:s}=window.ninja_footables||{};w.post(r,{action:"ninja_table_wp_fct_add_to_cart",nonce:s,table_id:(i==null?void 0:i.table_id)||this.settings.table_id,products:o}).done(h=>{h!=null&&h.success&&h.cart_html?(w(".ninjatable_cart_wrapper.fluent-cart").length?w(".ninjatable_cart_wrapper.fluent-cart").replaceWith(h.cart_html):w(".ninja_table_wrapper").append(h.cart_html),n&&n.addClass("added").removeClass("loading"),setTimeout(()=>{n&&n.removeClass("loading"),e&&e.prop("disabled",!1).removeData("processing")},1e3)):(n&&n.removeClass("loading"),e&&e.prop("disabled",!1).removeData("processing"))}).fail(()=>{n&&n.removeClass("loading"),e&&e.prop("disabled",!1).removeData("processing")})}}const b=window.jQuery;class E{static init(o,e){this.$table=o,this.settings=e,this.appendStyles()}static createComparisonModal(o,e){if(o.length<2||o.length>5)return alert("Select 2–5 products");const n=`nt_cmp_${e.table_id}`;b(`#${n}`).remove(),b("body").append(`
            <div id="${n}" class="nt_cmp_overlay">
                <div class="nt_cmp_content">
                    <div class="nt_cmp_header">
                        <h3>Compare (${o.length})</h3>
                        <button class="nt_cmp_close">×</button>
                    </div>
                    <div class="nt_cmp_body">
                        <div id="cmp_${n}" class="loading">Loading comparison...</div>
                    </div>
                    <div class="nt_cmp_footer">
                        <button class="nt_btn nt_btn_sec" id="close_cmp">Close</button>
                    </div>
                </div>
            </div>`),b(`#${n}`).fadeIn(),setTimeout(()=>this.render(o,e,n),80),b(`#${n}`).on("click",".nt_cmp_close, #close_cmp",()=>this.close(n)),b(`#${n}`).on("click",i=>i.target===i.currentTarget&&this.close(n)),b(document).on("keydown.cmp",i=>i.key==="Escape"&&this.close(n))}static close(o){b(`#${o}`).fadeOut(()=>{b(`#${o}`).remove(),b(document).off("keydown.cmp")})}static render(o,e,n){let i=`
            <div class="cmp_wrap">
                <table class="nt_cmp_table">
                    <thead>
                        <tr>
                            <th class="prop">Feature</th>
                            ${o.map(r=>`<th>Product ${o.indexOf(r)+1}</th>`).join("")}
                        </tr>
                    </thead>
                    <tbody>`;(e.columns||[]).forEach(r=>{!r.visible||r.key.includes("editing")||o.some(s=>s[r.key])&&(i+=`<tr data-is_compare_row="true">
                                <td class="prop">${r.title}</td>`,o.forEach(s=>{const h=s.cart.product_id,m=s[r.key]||"",v=/buy|add_to_cart/.test(r.key)?"act":/quantity/.test(r.key)?"qty":/image/.test(r.key)?"img":/price/.test(r.key)?"price":"val";i+=`<td class="${v}" data-pid="${h}" data-key="${r.key}">${m}</td>`}),i+="</tr>")}),i+=`</tbody>
                </table>
            </div>`,b(`#cmp_${n}`).html(i),this.bindEvents(b(`#${n}`),e)}static bindEvents(o,e){const n=o.find(".nt_cmp_table");k.init(n,e)}static appendStyles(){b("#nt_cmp_style").length||b("head").append(`
            <style id="nt_cmp_style">
                /* Overlay & Modal */
                .nt_cmp_overlay {
                    position: fixed;
                    top: 0; left: 0;
                    width: 100%; height: 100%;
                    background: rgba(0,0,0,0.6);
                    z-index: 10000;
                    display: none;
                    overflow: auto;
                    padding: 20px;
                    box-sizing: border-box;
                }

                .nt_cmp_content {
                    margin: 40px auto;
                    background: #fff;
                    border-radius: 12px;
                    max-width: 95%;
                    width: 1200px;
                    max-height: 90vh;
                    overflow: hidden;
                    box-shadow: 0 15px 40px rgba(0,0,0,0.3);
                    display: flex;
                    flex-direction: column;
                }

                /* Header - Compact */
                .nt_cmp_header {
                    padding: 14px 20px;
                    background: #f8f9fa;
                    border-bottom: 1px solid #eee;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    flex-shrink: 0;
                }

                .nt_cmp_header h3 {
                    margin: 0;
                    font-size: 18px;
                    font-weight: 600;
                    color: #333;
                }

                .nt_cmp_close {
                    background: none;
                    border: none;
                    font-size: 28px;
                    cursor: pointer;
                    color: #999;
                    padding: 0;
                    width: 36px;
                    height: 36px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }

                .nt_cmp_close:hover {
                    color: #333;
                }

                /* Body - Scrollable */
                .nt_cmp_body {
                    flex: 1;
                    overflow: auto;
                    padding: 0;
                }

                .cmp_wrap {
                    overflow: auto;
                    min-width: 800px;
                }

                /* Table Styling */
                .nt_cmp_table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 14px;
                    background: #fff;
                }

                .nt_cmp_table th,
                .nt_cmp_table td {
                    padding: 12px 16px;
                    border: 1px solid #e0e0e0;
                    text-align: left;
                    vertical-align: top;
                }

                .nt_cmp_table th {
                    background: #f5f5f5;
                    font-weight: 600;
                    color: #333;
                    position: sticky;
                    top: 0;
                    z-index: 10;
                    font-size: 13px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }

                .prop {
                    width: 180px;
                    min-width: 180px;
                    background: #f8f9fa !important;
                    font-weight: 600;
                    position: sticky;
                    left: 0;
                    z-index: 11;
                    box-shadow: 2px 0 5px rgba(0,0,0,0.05);
                }

                /* Cell Types */
                .act a, .act button {
                    display: inline-block;
                    padding: 8px 14px;
                    background: #a46497;
                    color: #fff;
                    border: none;
                    border-radius: 6px;
                    font-size: 13px;
                    cursor: pointer;
                    text-decoration: none;
                    font-weight: 500;
                }

                .act a:hover, .act button:hover {
                    background: #8b3f7a;
                }

                .act a.loading, .act button.loading {
                    opacity: 0.7;
                    cursor: not-allowed;
                }

                .act a.added {
                    background: #28a745;
                }

                .img img {
                    max-width: 80px;
                    max-height: 80px;
                    object-fit: cover;
                    border-radius: 6px;
                    border: 1px solid #eee;
                }

                .qty input {
                    width: 70px;
                    padding: 6px 8px;
                    text-align: center;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                }

                .price {
                    font-weight: 600;
                    color: #e91e63;
                    font-size: 15px;
                }

                /* Footer */
                .nt_cmp_footer {
                    padding: 16px 20px;
                    border-top: 1px solid #eee;
                    text-align: right;
                    background: #f8f9fa;
                    flex-shrink: 0;
                }

                .nt_btn_sec {
                    padding: 10px 20px;
                    background: #6c757d;
                    color: #fff;
                    border: none;
                    border-radius: 6px;
                    cursor: pointer;
                    font-size: 14px;
                }

                .nt_btn_sec:hover {
                    background: #5a6268;
                }

                /* Responsive */
                @media (max-width: 768px) {
                    .nt_cmp_content {
                        margin: 10px auto;
                        width: calc(100% - 20px);
                        max-height: 95vh;
                    }

                    .nt_cmp_header {
                        padding: 12px 16px;
                    }

                    .nt_cmp_header h3 {
                        font-size: 16px;
                    }

                    .nt_cmp_table th,
                    .nt_cmp_table td {
                        padding: 10px 12px;
                        font-size: 13px;
                    }

                    .prop {
                        width: 140px;
                        min-width: 140px;
                    }

                    .cmp_wrap {
                        min-width: 600px;
                    }
                }

                @media (max-width: 480px) {
                    .cmp_wrap {
                        min-width: 500px;
                    }

                    .act a, .act button {
                        padding: 6px 10px;
                        font-size: 12px;
                    }
                }
            </style>
        `)}}jQuery(document).ready(g=>{const e={},n={};let i=null;const r=a=>{var t,c;return(c=(t=a.attr("class"))==null?void 0:t.match(/nt_row_id_(\S+)/))==null?void 0:c[1]},s=a=>a.find("tbody tr:visible").map((t,c)=>{const _=g(c),d=r(_);return d&&_.find(".ninja-compare-checkbox:checked").length?d:null}).get().filter(Boolean),h=(a,t)=>{var _,d;if(t.provider!=="wp_fct"||((d=(_=t.settings)==null?void 0:_.appearance_settings)==null?void 0:d.show_bulk_actions)!=="yes")return;E.init(a,t);const c=t.table_id;e[c]=[],n[c]=!1,i=t,m(a,c,t),v(a,c),e[c]=s(a),x(a,c)},m=(a,t,c)=>{const d=a.closest(".ninja_table_wrapper").find(".ninja-bulk-actions-tr");d.length&&(d.off("change.bulk").off("click.bulk"),d.on("change.bulk",".ninja-bulk-action-select",function(){const p=g(this).val();g(this).data("action",p),j(a,t,p)}),d.on("click.bulk",".ninja-bulk-apply-btn",function(){const p=d.find(".ninja-bulk-action-select").data("action");if(!p||!e[t].length)return;const f=$(a,t);if(p==="compare")E.createComparisonModal(f,i),C(a,t);else if(p==="add_to_cart"){const l=f.map(u=>u.cart);k._ajaxAdd(l,null,"",c),C(a,t)}}),d.find(".ninja-bulk-apply-btn").attr("data-table-id",t))},v=(a,t)=>{a.off("change.bulk").on("change.bulk",".ninja-compare-checkbox",function(){if(n[t])return;const c=g(this),_=c.closest("tr");if(!r(_))return c.prop("checked",!1);const p=a.find(".ninja-compare-checkbox:checked").length;if(c.prop("checked")&&p>20){c.prop("checked",!1);return}e[t]=s(a),x(a,t)}),a.off("click.toggle").on("click.toggle",".ninja-compare-checkbox-toggle",function(c){c.stopPropagation();const _=g(this);setTimeout(()=>{const d=_.prop("checked"),p=a.find("tbody tr:visible").filter((f,l)=>r(g(l)));if(n[t]=!0,p.find(".ninja-compare-checkbox").prop("checked",!1),d&&e[t].length<20){let f=0;p.each(function(){if(f>=20)return!1;const l=g(this).find(".ninja-compare-checkbox");l.is(":visible")&&(l.prop("checked",!0),f++)})}n[t]=!1,e[t]=s(a),x(a,t)},0)})},j=(a,t,c)=>{const _=FooTable.get(a);_&&(n[t]=!0,_.rows.all.forEach(d=>{const p=d.$el,f=p.find(".ninja-compare-checkbox");if(!f.length)return;const l=p.find(".nt_fct_variations_wrapper"),u=l.length>0,y=u&&l.find(".nt_fct_variations_select").val()||0,q=c==="add_to_cart"?!u||y&&parseInt(y,10)>0:!0;f.css("visibility",q?"visible":"hidden").prop("checked",q?f.prop("checked"):!1)}),n[t]=!1,e[t]=s(a),x(a,t))},$=(a,t)=>{const c=[],_=FooTable.get(a);return _&&_.rows.all.forEach(d=>{var I;const p=r(d.$el);if(!e[t].includes(p))return;const f=z=>l.find(z).length?l.find(z):l.next().find(z),l=d.$el,u=f(".nt_fct_add_cart_wrapper .nt_button"),y=parseInt(u.data("product_id"))||0,q=parseInt(u.data("variation_id")||0,10),A=parseInt(u.data("quantity")||1,10),M={product_id:y,variation_id:q,quantity:A},S=(I=l.attr("class").match(/nt_row_id_(\S+)/))==null?void 0:I[1];c.push({cart:M,row_id:S,...d.value})}),c},C=(a,t)=>{n[t]=!0,a.find(".ninja-compare-checkbox").prop("checked",!1),n[t]=!1,e[t]=[],x(a,t)},x=(a,t)=>{const c=a.closest(".ninja_table_wrapper"),_=a.find(".ninja-compare-checkbox-toggle"),d=a.find("tbody tr:visible .ninja-compare-checkbox"),p=d.filter(":checked").length,f=d.length;_.prop({indeterminate:p>0&&p<f&&p<20,checked:p>0&&(p>=20||p===f)});const l=e[t].length>0,u=c.find(".ninja-bulk-action-select").data("action");c.find(".ninja-bulk-action-select").prop("disabled",!l),c.find(".ninja-bulk-apply-btn").prop("disabled",!l||!u)};g(document).on("ninja_table_loaded",(a,t,c)=>{try{h(t,c)}catch(_){console.error("Bulk Compare Init Error:",_)}})})})();
