document.addEventListener('DOMContentLoaded', function(){
  const tabs = Array.from(document.querySelectorAll('.tab'));
  if(!tabs.length) return;

  // mark active based on current filename
  const path = window.location.pathname.replace(/\\/g,'/');
  const current = path.substring(path.lastIndexOf('/')+1);
  tabs.forEach(t=>{
    const href = t.getAttribute('href') || '';
    const hfile = href.split('/').pop();
    if(hfile === current) t.classList.add('active');
  });

  // quick visual toggle before navigation
  tabs.forEach(t=>{
    t.addEventListener('click', function(){
      tabs.forEach(x=>x.classList.remove('active'));
      this.classList.add('active');
      // allow default navigation to proceed
    });
  });
});
