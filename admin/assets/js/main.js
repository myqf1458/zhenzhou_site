/*=============== 登录页密码显示隐藏 ===============*/
const passwordAccess = (loginPass, loginEye) => {
    const input = document.getElementById(loginPass),
       iconEye = document.getElementById(loginEye)
 
    iconEye.addEventListener('click', () => {
       
       input.type === 'password' ? input.type = 'text'
          : input.type = 'password'
 
       // 图标变化
       iconEye.classList.toggle('ri-eye-fill')
       iconEye.classList.toggle('ri-eye-off-fill')
    })
 }
 passwordAccess('password', 'loginPassword')
 
 /*=============== 注册页密码显示隐藏 ===============*/
 const passwordRegister = (loginPass, loginEye) => {
    const input = document.getElementById(loginPass),
       iconEye = document.getElementById(loginEye)
 
    iconEye.addEventListener('click', () => {
       input.type === 'password' ? input.type = 'text'
          : input.type = 'password'
 
       iconEye.classList.toggle('ri-eye-fill')
       iconEye.classList.toggle('ri-eye-off-fill')
    })
 }
 passwordRegister('passwordCreate', 'loginPasswordCreate')
 
 /*=============== 切换登录/注册页面 ===============*/
 const loginAcessRegister = document.getElementById('loginAccessRegister'),
    buttonRegister = document.getElementById('loginButtonRegister'),
    buttonAccess = document.getElementById('loginButtonAccess')
 
 buttonRegister.addEventListener('click', () => {
    loginAcessRegister.classList.add('active')
 })
 
 buttonAccess.addEventListener('click', () => {
    loginAcessRegister.classList.remove('active')
 })
 