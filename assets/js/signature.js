// assets/js/signature.js
class SignaturePad {
    constructor(canvasId) {
        this.canvas = document.getElementById(canvasId);
        this.ctx = this.canvas.getContext('2d');
        this.isDrawing = false;
        this.lastX = 0;
        this.lastY = 0;
        
        this.init();
    }
    
    init() {
        this.ctx.strokeStyle = '#000';
        this.ctx.lineWidth = 2;
        this.ctx.lineCap = 'round';
        
        this.canvas.addEventListener('mousedown', this.startDrawing.bind(this));
        this.canvas.addEventListener('mousemove', this.draw.bind(this));
        this.canvas.addEventListener('mouseup', this.stopDrawing.bind(this));
        this.canvas.addEventListener('mouseout', this.stopDrawing.bind(this));
        
        // دعم اللمس للأجهزة اللوحية
        this.canvas.addEventListener('touchstart', this.startDrawing.bind(this));
        this.canvas.addEventListener('touchmove', this.draw.bind(this));
        this.canvas.addEventListener('touchend', this.stopDrawing.bind(this));
    }
    
    startDrawing(e) {
        this.isDrawing = true;
        [this.lastX, this.lastY] = this.getCoordinates(e);
    }
    
    draw(e) {
        if (!this.isDrawing) return;
        
        e.preventDefault();
        const [x, y] = this.getCoordinates(e);
        
        this.ctx.beginPath();
        this.ctx.moveTo(this.lastX, this.lastY);
        this.ctx.lineTo(x, y);
        this.ctx.stroke();
        
        [this.lastX, this.lastY] = [x, y];
    }
    
    stopDrawing() {
        this.isDrawing = false;
    }
    
    getCoordinates(e) {
        if (e.type.includes('touch')) {
            const touch = e.touches[0] || e.changedTouches[0];
            const rect = this.canvas.getBoundingClientRect();
            return [
                touch.clientX - rect.left,
                touch.clientY - rect.top
            ];
        } else {
            return [
                e.offsetX,
                e.offsetY
            ];
        }
    }
    
    clear() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
    }
    
    getSignatureData() {
        return this.canvas.toDataURL('image/png');
    }
}