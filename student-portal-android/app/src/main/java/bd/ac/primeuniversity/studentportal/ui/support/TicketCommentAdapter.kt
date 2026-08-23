package bd.ac.primeuniversity.studentportal.ui.support

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.data.model.TicketAttachment
import bd.ac.primeuniversity.studentportal.data.model.TicketComment
import bd.ac.primeuniversity.studentportal.databinding.ItemTicketCommentBinding
import com.google.android.material.chip.Chip

/** Public comment thread of an IT support ticket. */
class TicketCommentAdapter(
    private val onAttachmentClick: (TicketAttachment) -> Unit,
) : ListAdapter<TicketComment, TicketCommentAdapter.VH>(Diff) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH =
        VH(ItemTicketCommentBinding.inflate(LayoutInflater.from(parent.context), parent, false))

    override fun onBindViewHolder(holder: VH, position: Int) = holder.bind(getItem(position))

    inner class VH(
        private val binding: ItemTicketCommentBinding,
    ) : RecyclerView.ViewHolder(binding.root) {

        fun bind(item: TicketComment) {
            binding.author.text = item.author
            binding.date.text = item.date
            binding.comment.text = item.comment

            binding.attachments.removeAllViews()
            binding.attachments.visibility =
                if (item.attachments.isEmpty()) View.GONE else View.VISIBLE
            item.attachments.forEach { att ->
                binding.attachments.addView(Chip(binding.root.context).apply {
                    text = att.name
                    setOnClickListener { onAttachmentClick(att) }
                })
            }
        }
    }

    private object Diff : DiffUtil.ItemCallback<TicketComment>() {
        override fun areItemsTheSame(oldItem: TicketComment, newItem: TicketComment) =
            oldItem.id == newItem.id

        override fun areContentsTheSame(oldItem: TicketComment, newItem: TicketComment) =
            oldItem == newItem
    }
}
