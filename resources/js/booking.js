import Alpine from 'alpinejs';

const words = {
  moveIntro:['選擇新時段，再確認改期。','Choose a new time, then confirm the change.'],
  original:['原預約時間（香港）','Original time (Hong Kong)'],
  privacy:['個人資料收集聲明（在新分頁開啟）','Personal data collection notice (opens in a new tab)'],whatsapp:['我同意診所透過 WhatsApp 發送此預約的確認、更改、提醒、付款通知及診後意見邀請。','Send me WhatsApp confirmations, changes, reminders, payment updates and post-visit feedback requests for this booking.'],moveError:['未能更改預約。請前往「我的預約」核對最新資料及可選時段。','Could not move the booking. Open My appointments to review the current booking and available times.'],manage:['我的預約','My appointments'],move:['更改預約','Reschedule appointment'],changed:['預約時段已更新','Appointment rescheduled'],confirmMove:['確認更改時段','Confirm new time'],open:['在診所網站完成預約','Continue on the clinic website'],email:['電郵（選填）','Email (optional)'],brand:['中醫門診','Chinese Medicine Clinic'],title:['線上預約','Book a visit'],intro:['選擇分店、服務及醫師，查看未來兩週的可預約時段。','Choose a branch, service and practitioner to view availability over the next two weeks.'],
  sample:['示範排班・請使用測試資料','Sample schedule · Use test details'],visit:['選擇門診','Choose a clinic'],branch:['分店','Branch'],service:['服務項目','Service'],doctor:['醫師','Practitioner'],dates:['日期及時間','Date and time'],time:['香港時間','Hong Kong time'],available:['可選時段','Available times'],summary:['預約摘要','Visit summary'],date:['日期','Date'],hour:['時間','Time'],next:['填寫患者資料','Enter patient details'],details:['患者資料','Patient details'],name:['患者姓名','Patient name'],phone:['香港聯絡電話','Hong Kong phone number'],submit:['確認預約','Confirm booking'],back:['返回選擇時段','Back to times'],pending:['處理中…','Submitting…'],loading:['正在載入時段…','Loading schedule…'],empty:['目前沒有可預約時段。','No appointments are available.'],retry:['重新載入','Reload'],failed:['未能載入時段，請重試。','Could not load availability. Please retry.'],conflict:['此時段剛被預約，請重新選擇。','That time has just been booked. Please choose another.'],invalid:['請核對姓名、8 位數香港電話及選填的電郵地址。','Check your name, 8-digit Hong Kong phone number and optional email address.'],error:['未能確認預約，請稍後重試。','Could not save the appointment. Please try again.'],done:['預約已確認','Booking confirmed'],ref:['預約編號','Booking reference'],again:['預約其他時段','Book another visit'],choose:['尚未選擇','Not selected'],clinic:['門診資料','Clinic details'],slot:['選擇時段','Choose a time'],review:['確認資料','Your details'],receipt:['預約詳情','Appointment details']
};

Alpine.data('booking', () => ({
  lang:'zh', catalog:{branches:[],services:[],practitioners:[],slots:[]}, branch:0,service:0,doctor:0,day:'',slot:null,
  moving:document.body.dataset.reference || '', expectedSlot:Number(document.body.dataset.expectedSlot), phase:1, name:'',phone:'',email:'',whatsapp:false,reference:'',error:'',loading:true,busy:false,
  t(k){return words[k][this.lang==='zh'?0:1]},
  toggle(){this.lang=this.lang==='zh'?'en':'zh';document.documentElement.lang=this.lang==='zh'?'zh-HK':'en';document.title=this.t('title')},
  label(row){return row?.['name_'+this.lang] || this.t('choose')},
  lookup(group,id){return this.label(this.catalog[group].find(x=>x.id===id))},
  fmt(day,opts){return day?new Intl.DateTimeFormat(this.lang==='zh'?'zh-HK':'en-GB',opts).format(new Date(day+'T12:00:00')):''},
  async init(){
    const params=new URLSearchParams(location.search);
    if(params.get('lang')==='en')this.toggle();
    await this.refresh();
    const requested=location.pathname==='/' ? Number(params.get('slot')) : 0;
    const slot=this.catalog.slots.find(s=>s.id===requested);
    if(slot){this.branch=slot.branch_id;this.service=slot.service_id;this.doctor=slot.practitioner_id;this.day=slot.starts_at.slice(0,10);this.slot=slot;this.phase=2}
    else if(requested)this.error='conflict';
  },
  async refresh(){
    this.loading=true; this.error='';
    try {
      const r=await fetch('/availability'); if(!r.ok)throw Error();
      this.catalog=await r.json();
      this.branch ||= this.catalog.branches.find(b=>this.catalog.slots.some(s=>s.branch_id===b.id))?.id || this.catalog.branches[0]?.id;
      this.align();
    } catch {this.error='failed'} finally {this.loading=false}
  },
  get services(){return this.catalog.services.filter(x=>this.catalog.slots.some(s=>s.branch_id===this.branch&&s.service_id===x.id))},
  get doctors(){return this.catalog.practitioners.filter(x=>this.catalog.slots.some(s=>s.branch_id===this.branch&&s.service_id===this.service&&s.practitioner_id===x.id))},
  get slots(){return this.catalog.slots.filter(s=>s.branch_id===this.branch&&s.service_id===this.service&&s.practitioner_id===this.doctor)},
  get days(){return [...new Set(this.slots.map(s=>s.starts_at.slice(0,10)))]},
  get times(){return this.slots.filter(s=>s.starts_at.startsWith(this.day))},
  align(){
    if(!this.services.some(x=>x.id===this.service))this.service=this.services[0]?.id || 0;
    if(!this.doctors.some(x=>x.id===this.doctor))this.doctor=this.doctors[0]?.id || 0;
    if(!this.days.includes(this.day))this.day=this.days[0] || '';
    this.slot=null;
  },
  async confirm(){
    if(this.busy||!this.slot)return;
    this.busy=true;this.error='';
    try {
      const r=await fetch(this.moving?'/appointments/'+this.moving:'/appointments',{method:this.moving?'PATCH':'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify(this.moving?{slot_id:this.slot.id,expected_slot:this.expectedSlot}:{slot_id:this.slot.id,name:this.name,phone:this.phone,email:this.email,whatsapp_opt_in:this.whatsapp})});
      if(r.status===409){this.phase=1;await this.refresh();this.error ||= this.moving?'moveError':'conflict';return}
      if(!r.ok){this.error=r.status===422?'invalid':'error';return}
      this.reference=(await r.json()).reference;this.phase=3;this.name='';this.phone='';this.email='';this.whatsapp=false;
    }catch{this.error='error'}finally{this.busy=false}
  }
}));
Alpine.start();
